<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * A signed, expiring token that names one order: "<entity id>.<expires>.<signature>". The payment form URL carries
 * it and the form posts it back, so it alone decides which order a payment-form request is for.
 */
class OrderToken
{
    // Long enough to come back to the form after a break, 3-D Secure and a retry. Payability is checked separately.
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function issue(OrderInterface $order): string
    {
        $expires = time() + self::TTL_SECONDS;
        $orderId = (int) $order->getEntityId();
        return $orderId . '.' . $expires . '.' . $this->sign($orderId, (int) $order->getQuoteId(), $expires);
    }

    /**
     * Returns null for a malformed, expired or forged token, a missing order and an order not paid with Reservepay
     * alike, so callers cannot tell them apart.
     */
    public function orderFor(string $token): ?OrderInterface
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$orderId, $expires, $signature] = $parts;
        if (!ctype_digit($orderId) || !ctype_digit($expires) || (int) $expires < time()) {
            return null;
        }

        try {
            $order = $this->orderRepository->get((int) $orderId);
        } catch (LocalizedException $e) {
            return null;
        }
        if (!hash_equals($this->sign((int) $orderId, (int) $order->getQuoteId(), (int) $expires), $signature)
            || !PaymentGroups::isReservepay($order->getPayment()?->getMethod())
        ) {
            return null;
        }
        return $order;
    }

    private function sign(int $orderId, int $quoteId, int $expires): string
    {
        // The crypt key also signs other data, so the prefix keeps these signatures from matching anything else.
        return $this->encryptor->hash(implode('|', ['reservepay-order-token', $orderId, $quoteId, $expires]));
    }
}
