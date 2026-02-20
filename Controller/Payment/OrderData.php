<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

class OrderData implements HttpPostActionInterface, CsrfAwareActionInterface
{
  protected $jsonFactory;
  protected $request;
  protected $orderRepository;
  protected $scopeConfig;
  protected $encryptor;
  protected $customerSession;
  protected $checkoutSession;
  protected $logger;
  protected $jsonSerializer;

  public function __construct(
    JsonFactory $jsonFactory,
    RequestInterface $request,
    OrderRepositoryInterface $orderRepository,
    ScopeConfigInterface $scopeConfig,
    EncryptorInterface $encryptor,
    CustomerSession $customerSession,
    CheckoutSession $checkoutSession,
    LoggerInterface $logger,
    JsonSerializer $jsonSerializer
  ) {
    $this->jsonFactory = $jsonFactory;
    $this->request = $request;
    $this->orderRepository = $orderRepository;
    $this->scopeConfig = $scopeConfig;
    $this->encryptor = $encryptor;
    $this->customerSession = $customerSession;
    $this->checkoutSession = $checkoutSession;
    $this->logger = $logger;
    $this->jsonSerializer = $jsonSerializer;
  }

  /**
   * @inheritDoc
   */
  public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
  {
    return null;
  }

  /**
   * @inheritDoc
   */
  public function validateForCsrf(RequestInterface $request): ?bool
  {
    return true;
  }

  public function execute()
  {
    $result = $this->jsonFactory->create();

    try {
      // Get order_id from POST body
      $content = $this->request->getContent();
      $params = $this->jsonSerializer->unserialize($content);
      $orderId = isset($params['order_id']) ? $params['order_id'] : null;

      if (!$orderId || !is_numeric($orderId)) {
        throw new \Exception('Invalid order ID.');
      }

      $order = $this->orderRepository->get($orderId);
      if (!$order || !$order->getId()) {
        throw new \Exception('Order not found.');
      }

      // Validate ownership
      if ($this->customerSession->isLoggedIn()) {
        // For logged-in customers: validate customer ID
        $customerId = $this->customerSession->getCustomerId();
        if ($order->getCustomerId() != $customerId) {
          $this->logger->warning('OrderData: Customer ID mismatch', [
            'session_customer_id' => $customerId,
            'order_customer_id' => $order->getCustomerId(),
            'order_id' => $orderId
          ]);
          throw new \Exception('Access Denied: Invalid order');
        }
      } else {
        // For guest users: validate via session first (fresh checkout), then cookie (resume payment)
        $sessionOrderId = $this->checkoutSession->getLastOrderId();

        if ($sessionOrderId && $sessionOrderId == $orderId) {
          // Valid - order from current checkout session
        } else {
          // Not in current session, validate cookie (resume payment flow)
          $token = $this->request->getCookie('payment_redirect_token');
          if (!$token) {
            throw new \Exception('Access Denied: Missing payment token');
          }
          try {
            $decryptedId = $this->encryptor->decrypt(base64_decode($token));
            if ($decryptedId != $orderId) {
              $this->logger->warning('OrderData: Order ID validation failed', [
                'session_order_id' => $sessionOrderId,
                'cookie_order_id' => $decryptedId,
                'requested_order_id' => $orderId
              ]);
              throw new \Exception('Access Denied: Invalid payment session');
            }
          } catch (\Exception $e) {
            throw new \Exception('Access Denied: Invalid payment token');
          }
        }
      }

      $allowedStates = [
        \Magento\Sales\Model\Order::STATE_NEW,
        \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
      ];
      if (!in_array($order->getState(), $allowedStates, true)) {
        throw new \Exception('Order is not in a valid state.');
      }

      $amount = (float) $order->getGrandTotal();
      if (!$amount || $amount <= 0) {
        throw new \Exception('Invalid order amount.');
      }

      $merchantId = $this->scopeConfig->getValue(
        'payment/reservepay_payment/merchantid',
        ScopeInterface::SCOPE_STORE
      );
      $installationId = $this->scopeConfig->getValue(
        'payment/reservepay_payment/installationid',
        ScopeInterface::SCOPE_STORE
      );

      $data = [
        'merchant_id' => $merchantId,
        'installation_id' => $installationId,
        'amount' => (int) round($amount * 100),
      ];

      return $result->setData($data);

    } catch (\Exception $e) {
      $this->logger->warning('Reservepay OrderData: ' . $e->getMessage());
      $result->setHttpResponseCode(403);
      return $result->setData(['error' => $e->getMessage()]);
    }
  }
}
