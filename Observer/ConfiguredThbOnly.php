<?php

namespace Reservepay\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\PaymentMethod;
use Reservepay\Payment\Model\Thb;

/**
 * Reservepay is available only when it has its credentials and only for a THB quote. The quote currency, not the base
 * currency Magento's canUseForCurrency() check sees, because the order is charged in the currency its grand total is
 * in. A quote without a currency code is not offered. Which payment methods the installation offers is the payment
 * form's call. Global, because Luma reloads the payment methods and places the order over the REST API.
 */
class ConfiguredThbOnly implements ObserverInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function execute(Observer $observer): void
    {
        $method = $observer->getEvent()->getMethodInstance();
        $result = $observer->getEvent()->getResult();
        if (!$method instanceof PaymentMethod || !$result->getData('is_available')) {
            return;
        }
        $quote = $observer->getEvent()->getQuote();
        $storeId = (int) ($quote ? $quote->getStoreId() : $method->getStore());
        if (($quote && $quote->getQuoteCurrencyCode() !== Thb::CODE) || !$this->config->isConfigured($storeId)) {
            $result->setData('is_available', false);
        }
    }
}
