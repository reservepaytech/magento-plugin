<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;

class PaymentFail implements HttpPostActionInterface
{
    protected $jsonFactory;
    protected $request;
    protected $orderRepository;
    protected $jsonSerializer;
    protected $orderManagement;
    protected $checkoutSession;
    protected $customerSession;
    protected $logger;
    protected $messageManager;
    protected $quoteRepository;
    protected $encryptor;
    protected $cookieManager;
    protected $cookieMetadataFactory;

    public function __construct(
        JsonFactory $jsonFactory,
        RequestInterface $request,
        OrderRepositoryInterface $orderRepository,
        JsonSerializer $jsonSerializer,
        OrderManagementInterface $orderManagement,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        LoggerInterface $logger,
        ManagerInterface $messageManager,
        CartRepositoryInterface $quoteRepository,
        EncryptorInterface $encryptor,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->jsonSerializer = $jsonSerializer;
        $this->orderManagement = $orderManagement;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->quoteRepository = $quoteRepository;
        $this->encryptor = $encryptor;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $content = $this->request->getContent();
            $params = $this->jsonSerializer->unserialize($content);
            $sessionId = isset($params['payment_session_id']) ? $params['payment_session_id'] : null;
            $orderId = isset($params['order_id']) ? $params['order_id'] : null;

            if (!$sessionId) {
                throw new \Exception("Session ID is missing in request body.");
            }

            if (!$orderId || !is_numeric($orderId)) {
                throw new \Exception("Access Denied: Invalid order ID");
            }

            $order = $this->orderRepository->get($orderId);
            if (!$order || !$order->getId()) {
                throw new \Exception("Order not found.");
            }

            // Validate ownership
            if ($this->customerSession->isLoggedIn()) {
                // For logged-in customers: validate customer ID
                $customerId = $this->customerSession->getCustomerId();
                if ($order->getCustomerId() != $customerId) {
                    $this->logger->warning('PaymentFail: Customer ID mismatch', [
                        'session_customer_id' => $customerId,
                        'order_customer_id' => $order->getCustomerId(),
                        'order_id' => $orderId
                    ]);
                    throw new \Exception("Access Denied: Invalid order");
                }
            } else {
                // For guest users: validate via session first, then cookie
                $sessionOrderId = $this->checkoutSession->getLastOrderId();

                if ($sessionOrderId && $sessionOrderId == $orderId) {
                    // Valid - order from current checkout session
                } else {
                    // Not in current session, validate cookie
                    $token = $this->request->getCookie('payment_redirect_token');
                    if (!$token) {
                        throw new \Exception("Access Denied: Missing payment token");
                    }
                    try {
                        $decryptedId = $this->encryptor->decrypt(base64_decode($token));
                        if ($decryptedId != $orderId) {
                            $this->logger->warning('PaymentFail: Order ID validation failed', [
                                'session_order_id' => $sessionOrderId,
                                'cookie_order_id' => $decryptedId,
                                'requested_order_id' => $orderId
                            ]);
                            throw new \Exception("Access Denied: Invalid payment session");
                        }
                    } catch (\Exception $e) {
                        throw new \Exception("Access Denied: Invalid payment token");
                    }
                }
            }

            $allowedStates = [
                \Magento\Sales\Model\Order::STATE_NEW,
                \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
            ];
            $currentState = $order->getState();
            if (!in_array($currentState, $allowedStates, true)) {
                $this->logger->warning('Payment attempted on invalid order state', [
                    'order_id' => $order->getId(),
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
