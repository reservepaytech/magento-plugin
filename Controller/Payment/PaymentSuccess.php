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

class PaymentSuccess implements HttpPostActionInterface, CsrfAwareActionInterface
{
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
        CartRepositoryInterface $quoteRepository
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

        $this->current_order_id = $this->checkoutSession->getLastOrderId();
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
            if (!$orderId) {
                throw new \Exception("Order ID is missing in request body.");
            }

            if ($this->current_order_id && !empty($this->current_order_id) && is_numeric($this->current_order_id)) {
                if ($orderId != $this->current_order_id) {
                    throw new \Exception("Access Denied: Invalid order ID");
                }
            }
            else {
                $token = $this->request->getCookie('payment_redirect_token');
                if (!$token) {
                    throw new \Exception("Access Denied: Invalid order ID");
                }
                $decryptedId = $this->encryptor->decrypt(base64_decode($token));
                if ($orderId != $decryptedId) {
                    throw new \Exception("Access Denied: Invalid order ID");
                }
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

            $amount = $order->getGrandTotal();
            $currency = $order->getOrderCurrencyCode();
            $encrypted = $this->scopeConfig->getValue(
                'payment/reservepay_payment/apikey',
                ScopeInterface::SCOPE_STORE
            );
            try {
                $api_key = $this->encryptor->decrypt($encrypted);
            } catch (\Exception $e) {
                $api_key = $encrypted;
            }

            $url = 'https://api.reservepay.com/merchants/find-payment';
            $payload = [
                'payment_id' => $paymentId,
            ];
            $this->curl->addHeader("User-Agent", "Magento2-ReservepayModule/1.0");
            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Accept", "application/json");
            $this->curl->addHeader("Authorization", "Bearer " . $api_key);
            $this->curl->setOption(CURLOPT_TIMEOUT, 10);
            $params = $this->jsonSerializer->serialize($payload);
            $this->curl->addHeader("Content-Length", strlen($params));

            $this->curl->post($url, $params);

            $status_code = $this->curl->getStatus();
            $body = $this->curl->getBody();
            if ($status_code < 200 || $status_code >= 300) {
                throw new \Exception("Reservepay API returned an error status=" . $status_code);
            }
            if (!$body || empty($body)) {
                throw new \Exception("Reservepay API returned an empty response.");
            }
            $resp = $this->jsonSerializer->unserialize($body);
            $status = $resp['status'] ?? 'UNKNOWN';
            $responseContent = $status;

            if (strtolower($status) === 'successful') {
                $this->invoiceOrder($order);
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
                            error_log("Failed to restore quote: " . $e->getMessage() . "\n");
                        }
                    }
                }
                $this->messageManager->addErrorMessage(__('Payment failure was detected. Your order has been cancelled. Please try again or use a different payment method.'));
                $responseContent = ['status' => 'cancelled', 'message' => 'Order has been cancelled due to payment failure.'];
            }

        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $responseContent = ['status' => 'fail', 'message' => 'Order not found.'];
        } catch (\Exception $e) {
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