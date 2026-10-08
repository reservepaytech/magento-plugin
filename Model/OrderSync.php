<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\App\Area;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;
use Reservepay\Payment\Model\Api\ApiException;
use Reservepay\Payment\Model\Api\Client;
use Reservepay\Payment\Model\Api\FoundPayment;

/**
 * Owns an order's Reservepay state. Every read-modify-write of the attempts runs under one per-order lock on a fresh
 * load, so the browser callbacks, the success page and the reconciler can all call it, repeatedly and at once.
 *
 * Stored in the order payment's additional_information:
 *   reservepay_attempts:       [{external_id: "m2-<host>-<hex>_order_<increment id>_<n>", payment_id: "pay_..."|null, session_id, created_at}]
 *   reservepay_paid_attempt:   external_id of the attempt that paid the order, set once
 *   reservepay_extra_captures: payment ids captured for this order that the order did not take, flagged for a refund
 *   reservepay_paid_with:      the method the paying attempt used, from find-payment, for example "Card (CARD)"
 */
class OrderSync
{
    // Anyone holding the order token can start sessions; a cap keeps the attempt list and each sync's API calls bounded.
    private const MAX_ATTEMPTS = 10;
    private const SESSION_ID_MAX_LENGTH = 255;

    public const ATTEMPTS = 'reservepay_attempts';
    public const PAID_ATTEMPT = 'reservepay_paid_attempt';
    public const EXTRA_CAPTURES = 'reservepay_extra_captures';
    public const PAID_WITH = 'reservepay_paid_with';
    public const TRIGGER_RECONCILER = 'reconciler';
    // sales_order_payment column. Set once the order has an attempt, NULL once nothing on it can change any more, so
    // its presence is what puts an order in the reconciler. The first value sorts before every real check.
    public const LAST_CHECKED = 'reservepay_last_checked';
    public const NEVER_CHECKED = '1970-01-01 00:00:00';

    // An attempt with no payment id that Reservepay still does not know after this long never reached it.
    private const NEVER_REACHED_SECONDS = 24 * 3600;
    // How long a paid or cancelled order keeps watching its other attempts for a capture.
    private const WATCH_SECONDS = 72 * 3600;

    // Longer than the client timeout, so a waiting caller outlasts the holder's API call.
    private const LOCK_TIMEOUT_SECONDS = 45;

    public function __construct(
        private readonly Client $client,
        private readonly ExternalId $externalId,
        private readonly LockManagerInterface $lockManager,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly UrlInterface $urlBuilder,
        private readonly Emulation $emulation,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Initiates the payment for a payment session. The same session reuses its attempt, a new session adds attempt n + 1.
     */
    public function startAttempt(int $orderId, string $sessionId): bool
    {
        return $this->locked($orderId, fn () => $this->startAttemptLocked($orderId, $sessionId)) ?? false;
    }

    /**
     * Asks Reservepay about every attempt and completes the order on the first paid one. The reconciler also watches
     * paid, cancelled and held orders for a capture that the order cannot take.
     *
     * @return string a StatusMap outcome
     */
    public function sync(int $orderId, string $trigger): string
    {
        return $this->locked($orderId, function () use ($orderId, $trigger) {
            $order = $this->load($orderId);
            $outcome = $this->syncLocked($order, $trigger);
            if ($trigger === self::TRIGGER_RECONCILER) {
                $this->leaveReconcilerWhenStale($order);
            }
            return $outcome;
        }) ?? StatusMap::UNKNOWN;
    }

    private function startAttemptLocked(int $orderId, string $sessionId): bool
    {
        if ($sessionId === '' || strlen($sessionId) > self::SESSION_ID_MAX_LENGTH) {
            $this->logger->warning('Reservepay payment start refused for an invalid session id', ['order_id' => $orderId]);
            return false;
        }
        $order = $this->load($orderId);
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            $this->logger->warning('Reservepay payment start refused for an order that is not pending payment', [
                'order' => $order->getIncrementId(),
                'state' => $order->getState(),
            ]);
            return false;
        }
        // The amount sent is the order's grand total read as baht, so a non-THB order must never start a payment.
        if ($order->getOrderCurrencyCode() !== Thb::CODE) {
            $this->logger->warning('Reservepay payment start refused for a non-THB order', [
                'order' => $order->getIncrementId(),
                'currency' => $order->getOrderCurrencyCode(),
            ]);
            return false;
        }

        $payment = $order->getPayment();
        $attempts = $this->attempts($payment);
        $index = $this->indexOf($attempts, 'session_id', $sessionId);
        if ($index === null && count($attempts) >= self::MAX_ATTEMPTS) {
            $this->logger->warning('Reservepay attempt limit reached', ['order' => $order->getIncrementId()]);
            return false;
        }
        if ($index === null) {
            // Saved before initiate, so a payment that initiate creates is always on record for the reconciler.
            $externalId = $this->externalId->forAttempt(
                (string) $order->getIncrementId(),
                (int) $order->getEntityId(),
                count($attempts) + 1
            );
            $attempts[] = $this->newAttempt($externalId, $sessionId);
            $index = array_key_last($attempts);
            $payment->setAdditionalInformation(self::ATTEMPTS, $attempts);
            if ($payment->getData(self::LAST_CHECKED) === null) {
                $payment->setData(self::LAST_CHECKED, self::NEVER_CHECKED);
            }
            $this->orderRepository->save($order);
        }
        $attempt = $attempts[$index];
        if ($attempt['payment_id'] !== null) {
            return true;
        }

        try {
            $paymentId = $this->initiate($order, $attempt);
        } catch (ApiException $e) {
            return false;
        }
        $attempts[$index]['payment_id'] = $paymentId;
        $payment->setAdditionalInformation(self::ATTEMPTS, $attempts);
        $this->orderRepository->save($order);
        $this->logger->info('Reservepay payment initiated', [
            'order' => $order->getIncrementId(),
            'external_id' => $attempt['external_id'],
            'payment_id' => $paymentId,
        ]);
        return true;
    }

    /**
     * Initiates the attempt's payment and returns its id.
     *
     * @throws ApiException
     */
    private function initiate(Order $order, array $attempt): string
    {
        try {
            return $this->client->initiatePaymentFlow((int) $order->getStoreId(), [
                'external_id' => $attempt['external_id'],
                'amount' => (string) Thb::satang($order->getGrandTotal()),
                'currency' => Thb::CODE,
                'payment_session_id' => $attempt['session_id'],
                'capture' => true,
                'return_url' => $this->urlBuilder->getUrl('checkout/onepage/success'),
            ]);
        } catch (ApiException $e) {
            // Reservepay answers NOT_FOUND when this session already has a payment, for example after an earlier
            // initiate timed out.
            $found = $e->errorCode === 'NOT_FOUND' ? $this->findAttempt($order, $attempt) : null;
            if ($found === null) {
                throw $e;
            }
            $this->logger->info('Reservepay payment session already had its payment', [
                'order' => $order->getIncrementId(),
                'external_id' => $attempt['external_id'],
                'payment_id' => $found->paymentId,
            ]);
            return $found->paymentId;
        }
    }

    private function syncLocked(Order $order, string $trigger): string
    {
        $payment = $order->getPayment();
        $isPaid = (bool) $payment->getAdditionalInformation(self::PAID_ATTEMPT);
        if ($isPaid || in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_HOLDED], true)) {
            // The shopper's callbacks return at once. Only the reconciler pays for the extra find-payment calls.
            if ($trigger === self::TRIGGER_RECONCILER) {
                $this->flagExtraCaptures($order);
            }
            return $isPaid ? StatusMap::PAID : StatusMap::UNKNOWN;
        }
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return StatusMap::UNKNOWN;
        }

        $outcomes = [];
        foreach (array_reverse($this->attempts($payment)) as $attempt) {
            try {
                $found = $this->findAttempt($order, $attempt);
            } catch (ApiException $e) {
                $outcomes[] = StatusMap::UNKNOWN;
                continue;
            }
            if ($found === null) {
                $outcomes[] = $this->notFoundOutcome($order, $attempt);
                continue;
            }
            $outcome = StatusMap::outcome($found->status);
            if ($outcome === StatusMap::PAID) {
                return $this->settle($order, $attempt, $found, $trigger);
            }
            $outcomes[] = $outcome;
        }
        return StatusMap::aggregate($outcomes);
    }

    private function settle(Order $order, array $attempt, FoundPayment $found, string $trigger): string
    {
        $mismatches = $this->mismatches($order, $found);
        if ($mismatches) {
            $this->logger->error('Reservepay payment does not match its order, order put on hold', [
                'order' => $order->getIncrementId(),
                'external_id' => $attempt['external_id'],
                'payment_id' => $found->paymentId,
                'mismatches' => $mismatches,
            ]);
            // Its hold note already names it, so the extra capture check must not flag it again.
            $flagged = $order->getPayment()->getAdditionalInformation(self::EXTRA_CAPTURES);
            $flagged = is_array($flagged) ? $flagged : [];
            $order->getPayment()->setAdditionalInformation(
                self::EXTRA_CAPTURES,
                array_values(array_unique([...$flagged, $found->paymentId]))
            );
            $order->hold();
            $order->addCommentToStatusHistory(__(
                'Reservepay payment %1 is %2 but its %3 does not match this order. Check it in the Reservepay dashboard before releasing the hold.',
                $found->paymentId,
                $found->status,
                implode(', ', $mismatches)
            ));
            $this->orderRepository->save($order);
            return StatusMap::UNKNOWN;
        }

        $payment = $order->getPayment();
        $attempts = $this->attempts($payment);
        $index = $this->indexOf($attempts, 'external_id', $attempt['external_id']);
        $attempts[$index]['payment_id'] = $found->paymentId;
        $payment->setAdditionalInformation(self::ATTEMPTS, $attempts);
        $payment->setAdditionalInformation(self::PAID_ATTEMPT, $attempt['external_id']);
        if (count($attempts) === 1) {
            // A paid order takes no new attempts, so with a single one there is nothing left to watch.
            $payment->setData(self::LAST_CHECKED, null);
        }
        $payment->setTransactionId($found->paymentId);
        $payment->setIsTransactionClosed(true);
        $payment->registerCaptureNotification($order->getBaseTotalDue(), true);
        // The shopper can switch methods inside the payment form, so the checkout pick is not necessarily what paid.
        $paidWith = $found->paidWith();
        if ($paidWith !== null) {
            $payment->setAdditionalInformation(self::PAID_WITH, $paidWith);
            $order->addCommentToStatusHistory(__('Reservepay payment %1 paid with %2.', $found->paymentId, $paidWith));
        }
        $this->orderRepository->save($order);

        $this->logger->info('Reservepay order paid', [
            'order' => $order->getIncrementId(),
            'external_id' => $attempt['external_id'],
            'payment_id' => $found->paymentId,
            'status' => $found->status,
            'paid_with' => $paidWith,
            'trigger' => $trigger,
        ]);

        // OrderSender, unlike InvoiceSender, does not emulate the store's frontend, so it fails from cron without this.
        $this->emulation->startEnvironmentEmulation((int) $order->getStoreId(), Area::AREA_FRONTEND, true);
        try {
            $this->orderSender->send($order);
        } catch (\Throwable $e) {
            $this->logger->error('Reservepay order email failed after payment', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        return StatusMap::PAID;
    }

    /**
     * Without a payment id, initiate may have timed out before Reservepay saw it. Once Reservepay still has no payment
     * for the attempt a day later, it never will, so the attempt fails and the order can fail or be cleaned up.
     */
    private function notFoundOutcome(Order $order, array $attempt): string
    {
        if ($attempt['payment_id'] !== null || $attempt['created_at'] > time() - self::NEVER_REACHED_SECONDS) {
            return StatusMap::UNKNOWN;
        }
        $this->logger->info('Reservepay attempt never reached Reservepay, counted as failed', [
            'order' => $order->getIncrementId(),
            'external_id' => $attempt['external_id'],
        ]);
        return StatusMap::FAILED;
    }

    /**
     * A paid order's other attempts, or the attempts of a cancelled or held unpaid order, can still be captured: a
     * second tab, or a late bank confirmation. The order cannot take that money, so a person must refund it, reinstate
     * the order or release the hold. Nothing here refunds, voids or changes the order state. Each payment is flagged once.
     */
    private function flagExtraCaptures(Order $order): void
    {
        $payment = $order->getPayment();
        $paidAttempt = $payment->getAdditionalInformation(self::PAID_ATTEMPT);
        $flagged = $payment->getAdditionalInformation(self::EXTRA_CAPTURES);
        $flagged = is_array($flagged) ? $flagged : [];
        $before = count($flagged);

        foreach ($this->attempts($payment) as $attempt) {
            if ($attempt['external_id'] === $paidAttempt
                || in_array($attempt['payment_id'], $flagged, true)
                || $attempt['created_at'] < time() - self::WATCH_SECONDS
            ) {
                continue;
            }
            try {
                $found = $this->findAttempt($order, $attempt);
            } catch (ApiException $e) {
                continue;
            }
            if ($found === null
                || StatusMap::outcome($found->status) !== StatusMap::PAID
                || in_array($found->paymentId, $flagged, true)
            ) {
                continue;
            }

            $flagged[] = $found->paymentId;
            [$logMessage, $note] = match (true) {
                $paidAttempt !== null => [
                    'Reservepay captured a second payment for a paid order, refund required',
                    __('Second Reservepay payment %1 captured for this order. Refund it in the Reservepay dashboard.', $found->paymentId),
                ],
                $order->getState() === Order::STATE_CANCELED => [
                    'Reservepay payment captured after its order was cancelled, refund or reinstate',
                    __('Reservepay payment %1 was paid after this order was cancelled. Refund it in the Reservepay dashboard or reinstate the order.', $found->paymentId),
                ],
                default => [
                    'Reservepay payment captured while its unpaid order is on hold, check it',
                    __('Reservepay payment %1 was captured while this order is on hold. Check it before releasing the hold, or refund it in the Reservepay dashboard.', $found->paymentId),
                ],
            };
            $this->logger->error($logMessage, [
                'order' => $order->getIncrementId(),
                'external_id' => $attempt['external_id'],
                'payment_id' => $found->paymentId,
                'status' => $found->status,
            ]);
            $order->addCommentToStatusHistory($note);
        }

        if (count($flagged) > $before) {
            $payment->setAdditionalInformation(self::EXTRA_CAPTURES, $flagged);
            $this->orderRepository->save($order);
        }
    }

    /**
     * Takes the order out of the reconciler once its newest attempt is older than the watch window.
     *
     * Nothing on it can change any more. A new attempt brings it back.
     */
    private function leaveReconcilerWhenStale(Order $order): void
    {
        $payment = $order->getPayment();
        $newest = max([0, ...array_column($this->attempts($payment), 'created_at')]);
        if ($newest >= time() - self::WATCH_SECONDS || $payment->getData(self::LAST_CHECKED) === null) {
            return;
        }
        $payment->setData(self::LAST_CHECKED, null);
        $this->orderRepository->save($order);
        $this->logger->info('Reservepay order left the reconciler, its attempts are too old to change', [
            'order' => $order->getIncrementId(),
        ]);
    }

    /**
     * @return FoundPayment|null null when Reservepay has no payment for the attempt
     * @throws ApiException when Reservepay could not answer
     */
    private function findAttempt(Order $order, array $attempt): ?FoundPayment
    {
        try {
            $found = $this->client->findPayment((int) $order->getStoreId(), $attempt['payment_id'] ?? $attempt['external_id']);
        } catch (ApiException $e) {
            if ($e->errorCode === 'NOT_FOUND') {
                return null;
            }
            throw $e;
        }
        // Without a stored payment id the lookup went by external_id, which Reservepay does not keep unique.
        $belongs = $attempt['payment_id'] !== null
            ? $found->paymentId === $attempt['payment_id']
            : $found->paymentSessionId === $attempt['session_id'];
        if (!$belongs) {
            $this->logger->warning('Reservepay payment does not belong to this attempt, ignored', [
                'order' => $order->getIncrementId(),
                'external_id' => $attempt['external_id'],
                'payment_id' => $found->paymentId,
            ]);
            return null;
        }
        return $found;
    }

    /**
     * @return string[] the fields that differ
     */
    private function mismatches(Order $order, FoundPayment $found): array
    {
        $mismatches = [];
        if ($found->amount !== Thb::satang($order->getGrandTotal())) {
            $mismatches[] = 'amount';
        }
        if (strtoupper($found->currency) !== Thb::CODE || $order->getOrderCurrencyCode() !== Thb::CODE) {
            $mismatches[] = 'currency';
        }
        return $mismatches;
    }

    private function attempts(Payment $payment): array
    {
        $attempts = $payment->getAdditionalInformation(self::ATTEMPTS);
        return is_array($attempts) ? array_values($attempts) : [];
    }

    private function newAttempt(string $externalId, string $sessionId): array
    {
        return [
            'external_id' => $externalId,
            'payment_id' => null,
            'session_id' => $sessionId,
            'created_at' => time(),
        ];
    }

    private function indexOf(array $attempts, string $field, string $value): ?int
    {
        foreach ($attempts as $i => $attempt) {
            if ($attempt[$field] === $value) {
                return $i;
            }
        }
        return null;
    }

    private function load(int $orderId): Order
    {
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderId);
        return $order;
    }

    private function locked(int $orderId, callable $work): mixed
    {
        $name = 'reservepay_order_' . $orderId;
        if (!$this->lockManager->lock($name, self::LOCK_TIMEOUT_SECONDS)) {
            $this->logger->warning('Reservepay could not lock the order in time', ['order_id' => $orderId]);
            return null;
        }
        try {
            return $work();
        } finally {
            $this->lockManager->unlock($name);
        }
    }
}
