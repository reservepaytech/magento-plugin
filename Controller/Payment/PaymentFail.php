<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;

class PaymentFail implements HttpPostActionInterface, CsrfAwareActionInterface
{
    protected $jsonFactory;
    protected $request;
    protected $orderRepository;
    protected $jsonSerializer;
    protected $orderManagement;
    protected $checkoutSession;
    protected $logger;
    protected $messageManager;
    protected $quoteRepository;

    public function __construct(
        JsonFactory $jsonFactory,
        RequestInterface $request,
        OrderRepositoryInterface $orderRepository,
        JsonSerializer $jsonSerializer,
        OrderManagementInterface $orderManagement,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        ManagerInterface $messageManager,
        CartRepositoryInterface $quoteRepository,
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->jsonSerializer = $jsonSerializer;
        $this->orderManagement = $orderManagement;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->quoteRepository = $quoteRepository;
    }

    public function execute()
    {
        error_log("KEHKEH: Executing PaymentFail controller...\n");

        $result = $this->jsonFactory->create();

        try {
            $content = $this->request->getContent();
            
            $params = $this->jsonSerializer->unserialize($content);
            $sessionId = isset($params['payment_session_id']) ? $params['payment_session_id'] : null;
            $orderId = isset($params['order_id']) ? $params['order_id'] : null;

            if (!$sessionId) {
                throw new \Exception("Session ID is missing in request body.");
            }
            if (!$orderId) {
                throw new \Exception("Order ID is missing in request body.");
            }

            $order = $this->orderRepository->get($orderId);
            if (!$order) {
                throw new \Exception("Order not found for the given Order ID.");
            }

            $payment = $order->getPayment();
            $paymentId = $payment->getAdditionalInformation('reservepay_payment_id');
            if (!$paymentId) {
                throw new \Exception("Payment ID not found in order payment additional information.");
            }

            if (!$order->canCancel()) {
                $this->logger->info("paymentfail controller: Order #{$order->getIncrementId()} cannot be canceled. Status: {$order->getStatus()}");
                throw new \Exception("Order cannot be cancelled.");
            }

            $orderId = $order->getId();
            $this->orderManagement->cancel($orderId);
            $quoteId = $order->getQuoteId();
            if ($quoteId) {
                try {
                    $quote = $this->quoteRepository->get($quoteId);
                    $quote->setIsActive(1);
                    $quote->setReservedOrderId(null);
                    $this->quoteRepository->save($quote);
                    $this->checkoutSession->replaceQuote($quote);
                } catch (\Exception $e) {
                    error_log("Failed to restore quote: " . $e->getMessage() . "\n");
                }
            }

            $this->messageManager->addErrorMessage(__('Payment failed or was cancelled. Your order has been cancelled. Please try again or use a different payment method.'));
            $responseContent = ['status' => 'cancelled', 'message' => 'Order has been cancelled due to payment failure.'];

        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $responseContent = ['status' => 'fail', 'message' => 'Order not found.'];
        } catch (\Exception $e) {
            $this->logger->critical('Order cancellation failed' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Payment failed or was cancelled. However, we were unable to cancel your order automatically.'));
            $responseContent = ['status' => 'error', 'message' => $e->getMessage()];
        }

        return $result->setData($responseContent);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}