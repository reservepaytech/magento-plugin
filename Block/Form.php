<?php

namespace Reservepay\Payment\Block;

use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Data\Form\FormKey;

class Form extends Template
{
    protected $orderRepository;
    protected $checkoutSession;
    protected $scopeConfig;
    protected $quoteRepository;
    protected $logger;
    protected $orderFactory;
    protected $request;
    protected $encryptor;
    protected $formKey;

    protected $current_order_id;

    public function __construct(
        Template\Context $context, 
        OrderRepositoryInterface $orderRepository,
        CheckoutSession $checkoutSession,
        ScopeConfigInterface $scopeConfig,
        CartRepositoryInterface $quoteRepository,
        LoggerInterface $logger,
        OrderFactory $orderFactory,
        RequestInterface $request,
        EncryptorInterface $encryptor,
        FormKey $formKey,
        array $data = []
    )
    {
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->scopeConfig = $scopeConfig;
        $this->quoteRepository = $quoteRepository;
        $this->logger = $logger;
        $this->orderFactory = $orderFactory;
        $this->request = $request;
        $this->encryptor = $encryptor;
        $this->formKey = $formKey;
        parent::__construct($context, $data);

        $this->current_order_id = $this->checkoutSession->getLastOrderId();
    }

    public function getQuoteTotal()
    {
        $order = $this->getOrder();
        if ($order && $order->getId()) {
            return (float) $order->getGrandTotal();
        }

        return null;
    }

    public function getPaymentConfig($key = null)
    {
        if ($key != null && !empty($key) && is_string($key)) {
            if ($key == 'apikey') {
                throw new \Exception('Access to API key is restricted.');
            }
            $value = $this->scopeConfig->getValue(
                'payment/reservepay_payment/' . $key,
                ScopeInterface::SCOPE_STORE
            );

            return $value;
        }

        $all = $this->scopeConfig->getValue(
            'payment/reservepay_payment',
            ScopeInterface::SCOPE_STORE
        );
        if (is_array($all) && isset($all['apikey'])) {
            unset($all['apikey']);
        }

        return $all;
    }

    public function getOrder()
    {   
        $order = $this->checkoutSession->getLastRealOrder();
        if ($order && $order->getId()) {
            return $this->validateOrder($order);
        }

        if ($this->current_order_id) {
            try {
                $order = $this->orderRepository->get($this->current_order_id);
                if ($order && $order->getId()) {
                    return $this->validateOrder($order);
                }
            } catch (\Exception $e) {
                // continue to other methods
            }
        }

        $token = $this->request->getCookie('payment_redirect_token');
        if ($token) {
            try {
                $decryptedId = $this->encryptor->decrypt(base64_decode($token));
                if (is_numeric($decryptedId)) {
                    $order = $this->orderRepository->get($decryptedId);
                    if ($order && $order->getId()) {
                        return $this->validateOrder($order);
                    }
                }
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    private function validateOrder($order)
    {
        $allowedStates = [
            \Magento\Sales\Model\Order::STATE_NEW,
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
        ];

        if (in_array($order->getState(), $allowedStates)) {
            return $order;
        }

        $this->logger->warning('Reservepay Form: Attempted access to invalid order state.', ['id' => $order->getId(), 'state' => $order->getState()]);
        return null;
    }

    public function isPaymentConfigured(): bool
    {
        $merchantId = $this->scopeConfig->getValue(
            'payment/reservepay_payment/merchantid',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $installationId = $this->scopeConfig->getValue(
            'payment/reservepay_payment/installationid',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $apiKey = $this->scopeConfig->getValue(
            'payment/reservepay_payment/apikey',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        return !empty($merchantId) && !empty($installationId) && !empty($apiKey);
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}