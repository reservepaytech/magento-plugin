<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Reservepay\Payment\Model\ClientInfo;

class PaymentSuccess implements HttpPostActionInterface
{
    const API_URL_FINDPAYMENT = 'https://api.reservepay.com/merchants/find-payment';

    protected $scopeConfig;
    protected $jsonFactory;
    protected $request;
    protected $orderRepository;
    protected $jsonSerializer;
    protected $curl;
    protected $urlBuilder;
    protected $encryptor;
    protected $invoiceService;
    protected $transaction;
    protected $invoiceSender;
    protected $orderManagement;
    protected $checkoutSession;
    protected $messageManager;
    protected $quoteRepository;
    protected $logger;
    protected $cookieManager;
    protected $cookieMetadataFactory;
    protected $searchCriteriaBuilder;

    protected $current_order_id;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        JsonFactory $jsonFactory,
        RequestInterface $request,
        OrderRepositoryInterface $orderRepository,
        JsonSerializer $jsonSerializer,
        Curl $curl,
        UrlInterface $urlBuilder,
        EncryptorInterface $encryptor,
        InvoiceService $invoiceService,
        Transaction $transaction,
        InvoiceSender $invoiceSender,
        OrderManagementInterface $orderManagement,
        CheckoutSession $checkoutSession,
        ManagerInterface $messageManager,
        CartRepositoryInterface $quoteRepository,
        LoggerInterface $logger,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->jsonFactory = $jsonFactory;
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->jsonSerializer = $jsonSerializer;
        $this->curl = $curl;
        $this->urlBuilder = $urlBuilder;
        $this->encryptor = $encryptor;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        $this->orderManagement = $orderManagement;
        $this->checkoutSession = $checkoutSession;
        $this->messageManager = $messageManager;
        $this->quoteRepository = $quoteRepository;
        $this->logger = $logger;
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

            // Check if this payment_id has already been processed
            $processedPaymentId = $payment->getAdditionalInformation('reservepay_processed_payment_id');
            if ($processedPaymentId === $paymentId) {
                // Payment already processed, return success without invoicing again
                $this->logger->info("Payment already processed", [
                    'payment_id' => $paymentId,
                    'order_id' => $orderId
                ]);
                return $result->setData("SUCCESSFUL");
            }

            $amount = $order->getGrandTotal();
            $currency = $order->getOrderCurrencyCode();
            $encrypted = $this->scopeConfig->getValue(
                'payment/reservepay_payment/apikey',
                ScopeInterface::SCOPE_STORE
            );
            try {
                $api_key = $this->encryptor->decrypt($encrypted);
            } catch (\Exception $e) {
                $this->logger->critical('Reservepay: Failed to decrypt API key.');
                throw new \Exception("Payment gateway configuration error.");
            }

            $payload = [
                'payment_id' => $paymentId,
            ];
            $this->curl->addHeader("User-Agent", ClientInfo::TOKEN);
            $this->curl->addHeader("Client-Version", ClientInfo::TOKEN);
            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Accept", "application/json");
            $this->curl->addHeader("Authorization", "Bearer " . $api_key);
            $this->curl->setOption(CURLOPT_TIMEOUT, 10);
            $params = $this->jsonSerializer->serialize($payload);
            $this->curl->addHeader("Content-Length", strlen($params));

            $this->curl->post(self::API_URL_FINDPAYMENT, $params);

            $status_code = $this->curl->getStatus();
            $body = $this->curl->getBody();
            if ($status_code < 200 || $status_code >= 300) {
                throw new \Exception("Reservepay API returned an error status=" . $status_code);
            }
            if (!$body || empty($body)) {
                throw new \Exception("Reservepay API returned an empty response.");
            }
            $resp = $this->jsonSerializer->unserialize($body);
            if (!is_array($resp)) {
                throw new \Exception("Invalid response format from Reservepay API.");
            }

            $status = $resp['status'] ?? 'UNKNOWN';
            $responseContent = $status;

            if (strtolower($status) === 'successful') {
                $this->invoiceOrder($order);

                // Mark this payment_id as processed to avoid duplicate invoicing
                $payment->setAdditionalInformation('reservepay_processed_payment_id', $paymentId);
                $this->orderRepository->save($order);
            }
            else {
                if ($order->canCancel()) {
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
                }
                $this->messageManager->addErrorMessage(__('Payment failure was detected. Your order has been cancelled. Please try again or use a different payment method.'));
                $responseContent = ['status' => 'cancelled', 'message' => 'Order has been cancelled due to payment failure.'];
            }

            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()->setPath('/');
            $this->cookieManager->deleteCookie('payment_redirect_token', $metadata);

        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $responseContent = ['status' => 'fail', 'message' => 'Order not found.'];
        } catch (\Exception $e) {
            $this->logger->critical('Critical error detected during payment confirmation: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Critical error during payment confirmation.'));
            $responseContent = ['status' => 'error', 'message' => $e->getMessage()];
        }

        return $result->setData($responseContent);
    }

    public function invoiceOrder($order)
    {
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            $invoice->getOrder()->setIsInProcess(true);
            
            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);

            $transactionSave = $this->transaction
                ->addObject($invoice)
                ->addObject($order);
            $transactionSave->save();

            $this->invoiceSender->send($invoice);
            $order->addCommentToStatusHistory(
                __('Payment completed successfully. Invoice #%1 created.', $invoice->getIncrementId())
            )->setIsCustomerNotified(true)->save();
        }
    }
}
