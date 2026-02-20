<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

class Form extends Action
{
  protected $resultPageFactory;
  protected $resultRedirectFactory;
  protected $orderRepository;
  protected $customerSession;
  protected $checkoutSession;
  protected $encryptor;
  protected $cookieManager;
  protected $cookieMetadataFactory;
  protected $messageManager;
  protected $logger;

  public function __construct(
    Context $context,
    PageFactory $resultPageFactory,
    RedirectFactory $resultRedirectFactory,
    OrderRepositoryInterface $orderRepository,
    CustomerSession $customerSession,
    CheckoutSession $checkoutSession,
    EncryptorInterface $encryptor,
    CookieManagerInterface $cookieManager,
    CookieMetadataFactory $cookieMetadataFactory,
    ManagerInterface $messageManager,
    LoggerInterface $logger
  ) {
    parent::__construct($context);
    $this->resultPageFactory = $resultPageFactory;
    $this->resultRedirectFactory = $resultRedirectFactory;
    $this->orderRepository = $orderRepository;
    $this->customerSession = $customerSession;
    $this->checkoutSession = $checkoutSession;
    $this->encryptor = $encryptor;
    $this->cookieManager = $cookieManager;
    $this->cookieMetadataFactory = $cookieMetadataFactory;
    $this->messageManager = $messageManager;
    $this->logger = $logger;
  }

  public function execute()
  {
    // Read order_id from path parameter (e.g., /reservepay/payment/form/order_id/34/)
    $orderId = $this->getRequest()->getParam('order_id');

    // If order_id is provided in URL, validate and set cookie
    if ($orderId) {
      try {
        if (!is_numeric($orderId)) {
          throw new \Exception('Invalid order ID.');
        }

        $order = $this->orderRepository->get($orderId);
        if (!$order || !$order->getId()) {
          throw new \Exception('Order not found.');
        }

        // If customer is logged in, verify they own the order
        if ($this->customerSession->isLoggedIn()) {
          $customerId = $this->customerSession->getCustomerId();
          if ($order->getCustomerId() != $customerId) {
            $this->logger->warning('Reservepay Form: Customer attempted to access another customer\'s order', [
              'customer_id' => $customerId,
              'order_id' => $orderId,
              'order_customer_id' => $order->getCustomerId()
            ]);
            throw new \Exception('You do not have permission to access this order.');
          }
        } else {
          // Guest checkout - verify via session
          $sessionOrderId = $this->checkoutSession->getLastOrderId();
          if (!$sessionOrderId || $sessionOrderId != $order->getId()) {
            throw new \Exception('You do not have permission to access this order.');
          }
        }

        // Verify order state
        $allowedStates = [
          \Magento\Sales\Model\Order::STATE_NEW,
          \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
        ];
        if (!in_array($order->getState(), $allowedStates, true)) {
          $this->messageManager->addNoticeMessage(__('This order is no longer pending payment.'));
          $resultRedirect = $this->resultRedirectFactory->create();
          return $resultRedirect->setPath('sales/order/view', ['order_id' => $order->getId()]);
        }

        // Verify payment method
        $payment = $order->getPayment();
        if ($payment->getMethod() !== 'reservepay_payment') {
          throw new \Exception('Invalid payment method for this order.');
        }

        // Set/refresh payment token cookie
        $encryptedOrderId = base64_encode($this->encryptor->encrypt((string)$order->getId()));
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
          ->setDuration(600)  // 10 minutes
          ->setPath('/')
          ->setHttpOnly(true)
          ->setSecure(true)
          ->setSameSite('Strict');
        $this->cookieManager->setPublicCookie('payment_redirect_token', $encryptedOrderId, $metadata);

      } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        $this->messageManager->addErrorMessage(__('Order not found.'));
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('/');
      } catch (\Exception $e) {
        $this->logger->error('Reservepay Form error: ' . $e->getMessage());
        $this->messageManager->addErrorMessage(__($e->getMessage()));
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('/');
      }
    }

    // Render payment form page
    return $this->resultPageFactory->create();
  }
}
