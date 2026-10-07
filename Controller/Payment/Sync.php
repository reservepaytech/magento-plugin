<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Reservepay\Payment\Model\OrderSync;
use Reservepay\Payment\Model\OrderToken;
use Reservepay\Payment\Model\StatusMap;

/**
 * Both SDK callbacks and the payment page's poll land here. The browser only asks for a check: OrderSync decides from
 * Reservepay what happened.
 */
class Sync implements HttpPostActionInterface
{
    // Not a StatusMap outcome: the token is bad or expired, so the browser cannot check this order at all.
    public const REFUSED = 'refused';

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly JsonSerializer $jsonSerializer,
        private readonly OrderToken $orderToken,
        private readonly OrderSync $orderSync,
        private readonly CheckoutSession $checkoutSession,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $params = $this->params();
        $order = $this->orderToken->orderFor((string) ($params['token'] ?? ''));
        $result = $this->jsonFactory->create();
        if (!$order) {
            $this->logger->warning('Reservepay order check refused');
            $this->messageManager->addErrorMessage(__('We could not open the payment page for this order.'));
            return $result->setHttpResponseCode(400)->setData(['outcome' => self::REFUSED]);
        }

        $outcome = $this->orderSync->sync((int) $order->getEntityId(), 'browser');
        if ($outcome === StatusMap::PAID) {
            $this->showOnSuccessPage($order);
        } elseif (($params['failed'] ?? false) === true) {
            // The payment form reported a failure, so the page reloads for the same order and starts a new attempt.
            // The order stays pending_payment, because a failed payment can still turn successful later.
            $this->messageManager->addErrorMessage(__('Your payment did not go through. Please try again.'));
        }
        return $result->setData(['outcome' => $outcome]);
    }

    /**
     * The success page shows the session's last order, which is another order when the shopper placed a second
     * one in another tab before paying this one.
     */
    private function showOnSuccessPage(OrderInterface $order): void
    {
        $this->checkoutSession->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getEntityId())
            ->setLastRealOrderId($order->getIncrementId());
    }

    private function params(): array
    {
        try {
            $params = $this->jsonSerializer->unserialize((string) $this->request->getContent());
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        return is_array($params) ? $params : [];
    }
}
