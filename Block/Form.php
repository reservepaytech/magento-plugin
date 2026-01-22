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

class Form extends Template
{
    protected $_orderRepository;
    protected $_checkoutSession;
    protected $_scopeConfig;
    protected $_quoteRepository;
    protected $_logger;
    protected $_orderFactory;
    protected $_request;
    protected $_encryptor;

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
        array $data = []
    )
    {
        $this->_orderRepository = $orderRepository;
        $this->_checkoutSession = $checkoutSession;
        $this->_scopeConfig = $scopeConfig;
        $this->_quoteRepository = $quoteRepository;
        $this->_logger = $logger;
        $this->_orderFactory = $orderFactory;
        $this->_request = $request;
        $this->_encryptor = $encryptor;
        parent::__construct($context, $data);
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
            $value = $this->_scopeConfig->getValue(
                'payment/reservepay_payment/' . $key,
                ScopeInterface::SCOPE_STORE
            );

            if ($key === 'apikey' && $value !== null) {
                try {
                    return $this->_encryptor->decrypt($value);
                } catch (\Exception $e) {
                    return $value;
                }
            }

            return $value;
        }

        $all = $this->_scopeConfig->getValue(
            'payment/reservepay_payment',
            ScopeInterface::SCOPE_STORE
        );

        if (is_array($all) && isset($all['apikey']) && $all['apikey'] !== null) {
            try {
                $all['apikey'] = $this->_encryptor->decrypt($all['apikey']);
            } catch (\Exception $e) {
                // leave original value on failure
            }
        }

        return $all;
    }

    public function getOrder()
    {   
        $order = $this->_checkoutSession->getLastRealOrder();
        if ($order && $order->getId()) {
            return $order;
        }

        $token = $this->_request->getCookie('payment_redirect_token');
        if ($token) {
            try {
                $decryptedId = $this->_encryptor->decrypt(base64_decode($token));           
                if (is_numeric($decryptedId)) {
                    $order = $this->_orderRepository->get($decryptedId);                
                    return $order;
                }
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }
}