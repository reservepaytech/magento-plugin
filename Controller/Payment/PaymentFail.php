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
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;

class PaymentFail implements HttpPostActionInterface
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
    protected $encryptor;
    protected $cookieManager;
    protected $cookieMetadataFactory;
    protected $searchCriteriaBuilder;

    protected $current_order_id;

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
        EncryptorInterface $encryptor,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder
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
        $this->encryptor = $encryptor;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;

        $this->current_order_id = $this->checkoutSession->getLastOrderId();
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $content = $this->request->getContent();
            
            $params = $this->jsonSerializer->unserialize($content);
            $sessionId = isset($params['payment_session_id']) ? $params['payment_session_id'] : null;
            $incrementId = isset($params['order_id']) ? $params['order_id'] : null;

            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $incrementId, 'eq')
                ->create();
            $orders = $this->orderRepository->getList($searchCriteria)->getItems();
            if (empty($orders)) {
                throw new \Exception("Order not found for the given Increment ID.");
            }
            $order = reset($orders);
            $orderId = $order->getId();

            if (!$sessionId) {
                throw new \Exception("Session ID is missing in request body.");
            }

            if ($this->current_order_id && !empty($this->current_order_id) && is_numeric($this->current_order_id)) {
                if ((string)$orderId !== (string)$this->current_order_id) {
                    throw new \Exception("Access Denied: Invalid order ID");
                }
            }
            else {
                $token = $this->request->getCookie('payment_redirect_token');
                if (!$token) {
                    throw new \Exception("Access Denied: Invalid order ID");
                }
                $decryptedId = $this->encryptor->decrypt(base64_decode($token));
                if ((string)$orderId !== (string)$decryptedId) {
                    throw new \Exception("Access Denied: Invalid order ID");
                }
            }

            $allowedStates = [
                \Magento\Sales\Model\Order::STATE_NEW,
                \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
            ];
            $currentState = $order->getState();
            if (!in_array($currentState, $allowedStates, true)) {
                $this->logger->warning('Payment attempted on invalid order state', [
                    'order_id' => $orderId,
                    'current_state' => $currentState,
                    'allowed_states' => $allowedStates
                ]);
                throw new \Exception("Order is not in a valid state for payment processing.");
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
                    $this->logger->error("Failed to restore quote: " . $e->getMessage());
                }
            }

            $this->messageManager->addErrorMessage(__('Payment failed or was cancelled. Your order has been cancelled. Please try again or use a different payment method.'));
            $responseContent = ['status' => 'cancelled', 'message' => 'Order has been cancelled due to payment failure.'];

            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()->setPath('/');
            $this->cookieManager->deleteCookie('payment_redirect_token', $metadata);

        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $responseContent = ['status' => 'fail', 'message' => 'Order not found.'];
        } catch (\Exception $e) {
            $this->logger->critical('Order cancellation failed' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Payment failed or was cancelled. However, we were unable to cancel your order automatically.'));
            $responseContent = ['status' => 'error', 'message' => $e->getMessage()];
        }

        return $result->setData($responseContent);
    }
}