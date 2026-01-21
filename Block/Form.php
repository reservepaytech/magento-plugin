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
        try {
            $order = $this->_checkoutSession->getLastRealOrder();
            error_log("Last Real Order from session: [" . print_r($order->getData(), true) . "]\n");
            $total = $order->getGrandTotal();
            error_log("Grand Total from last real order: [" . $total . "]\n");
            $quote = $this->_checkoutSession->getQuote();
            error_log("Quote: " . print_r($quote->getData(), true) . "\n");

            if (!$quote || !$quote->getId()) {
                $quoteId = $this->_checkoutSession->getQuoteId();
                error_log("Quote ID from session: [" . $quoteId . "]\n");
                if ($quoteId && $this->_quoteRepository) {
                    $quote = $this->_quoteRepository->get($quoteId);
                    error_log("Loaded Quote: " . print_r($quote->getData(), true) . "\n");
                }
            }

            if ($quote && $quote->getId()) {
                if (method_exists($quote, 'collectTotals')) {
                    $quote->collectTotals();
                }
                $total = $quote->getGrandTotal();
                if ($total !== null) {
                    return (float) $total;
                }
            }

            // If quote was converted to an order, it may have a reserved order increment id
            if ($quote && $quote->getReservedOrderId() && $this->_orderFactory) {
                try {
                    $reserved = $quote->getReservedOrderId();
                    $order = $this->_orderFactory->create()->loadByIncrementId($reserved);
                    if ($order && $order->getId()) {
                        return (float) $order->getGrandTotal();
                    }
                } catch (\Exception $e) {
                    if ($this->_logger) {
                        $this->_logger->warning('Reservepay\\Payment\\Block\\Form::load order by reserved id failed: ' . $e->getMessage());
                    }
                }
            }

            // Fallback: if quote is not present (e.g. after order placement), use last placed order
            $order = $this->getOrder();
            if ($order && $order->getId()) {
                return (float) $order->getGrandTotal();
            }
        } catch (\Exception $e) {
            if ($this->_logger) {
                $this->_logger->error('Reservepay\\Payment\\Block\\Form::getQuoteTotal error: ' . $e->getMessage());
            }
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

    public function ping()
    {
        return "pong";
    }

    /**
     * Retrieve last placed order using checkout session
     *
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    public function getOrder()
    {   
        $order = $this->_checkoutSession->getLastRealOrder();
        if ($order && $order->getId()) {
            error_log("Last Real Order from session: [" . print_r($order->getData(), true) . "]\n");
            return $order;
        }

        $token = $this->_request->getCookie('payment_redirect_token');
        if ($token) {
            error_log("Found payment_redirect_token cookie: [" . $token . "]\n");
            try {
                // Decode Base64 -> Decrypt
                $decryptedId = $this->_encryptor->decrypt(base64_decode($token));
            
                if (is_numeric($decryptedId)) {
                    $order = $this->_orderRepository->get($decryptedId);
                
                    // Optional: Clear the cookie so it can't be reused
                    // $this->cookieManager->deleteCookie('payment_redirect_token');
                
                    return $order;
                }
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }
}