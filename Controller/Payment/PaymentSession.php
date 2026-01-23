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
use Magento\Checkout\Model\Session as CheckoutSession;

class PaymentSession implements HttpPostActionInterface, CsrfAwareActionInterface
{
    const API_URL_STARTPAYMENT = 'https://api.reservepay.com/merchants/initiate-payment-flow';

    protected $scopeConfig;
    protected $jsonFactory;
    protected $request;
    protected $orderRepository;
    protected $jsonSerializer;
    protected $curl;
    protected $urlBuilder;
    protected $encryptor;
    protected $checkoutSession;

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
        CheckoutSession $checkoutSession
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->jsonFactory = $jsonFactory;
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->jsonSerializer = $jsonSerializer;
        $this->curl = $curl;
        $this->urlBuilder = $urlBuilder;
        $this->encryptor = $encryptor;
        $this->checkoutSession = $checkoutSession;

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

            $payload = [
                'payment_session_id' => $sessionId,
                'capture' => true,
                'amount' => (int) round( $amount * 100 ),
                'currency' => $currency,
                'return_url' => $this->urlBuilder->getUrl( '/checkout/order-received/' . $orderId),
            ];
            $this->curl->addHeader("User-Agent", "Magento2-ReservepayModule/1.0");
            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Accept", "application/json");
            $this->curl->addHeader("Authorization", "Bearer " . $api_key);
            $this->curl->setOption(CURLOPT_TIMEOUT, 10);
            $params = $this->jsonSerializer->serialize($payload);
            $this->curl->addHeader("Content-Length", strlen($params));

            $this->curl->post(self::API_URL_STARTPAYMENT, $params);

            $status_code = $this->curl->getStatus();
            $body = $this->curl->getBody();
            if ($status_code < 200 || $status_code >= 300) {
                throw new \Exception("Reservepay API returned an error status=" . $status_code);
            }
            if (!$body || empty($body)) {
                throw new \Exception("Reservepay API returned an empty response.");
            }
            $payment_id = $this->jsonSerializer->unserialize($body);
            $payment = $order->getPayment();
            $payment->setAdditionalInformation('reservepay_payment_id', $payment_id);
            $this->orderRepository->save($order);
            
            $responseContent = $payment_id;

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
}