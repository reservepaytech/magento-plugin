<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;
use Reservepay\Payment\Model\OrderSync;
use Reservepay\Payment\Model\OrderToken;

/**
 * The SDK has a payment session for the order: initiate the payment for it.
 */
class PaymentSession implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly JsonSerializer $jsonSerializer,
        private readonly OrderToken $orderToken,
        private readonly OrderSync $orderSync,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $params = $this->params();
        $sessionId = (string) ($params['payment_session_id'] ?? '');
        $order = $sessionId === '' ? null : $this->orderToken->orderFor((string) ($params['token'] ?? ''));
        if (!$order) {
            $this->logger->warning('Reservepay payment session refused');
        }

        $result = $this->jsonFactory->create();
        if (!$order || !$this->orderSync->startAttempt((int) $order->getEntityId(), $sessionId)) {
            return $result->setHttpResponseCode(400)->setData([
                'status' => 'error',
                'message' => __('We could not start the payment. Please try again.'),
            ]);
        }
        return $result->setData(['status' => 'ok']);
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
