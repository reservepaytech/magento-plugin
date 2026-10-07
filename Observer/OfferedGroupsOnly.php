<?php

namespace Reservepay\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Reservepay\Payment\Model\OfferedGroups;
use Reservepay\Payment\Model\PaymentMethod;

/**
 * A group's payment method is available only when the Reservepay installation offers that group. Global, because
 * Luma reloads the payment methods and places the order over the REST API.
 */
class OfferedGroupsOnly implements ObserverInterface
{
    public function __construct(private readonly OfferedGroups $offeredGroups)
    {
    }

    public function execute(Observer $observer): void
    {
        $method = $observer->getEvent()->getMethodInstance();
        $result = $observer->getEvent()->getResult();
        if (!$method instanceof PaymentMethod || $method->getGroup() === null || !$result->getData('is_available')) {
            return;
        }
        $quote = $observer->getEvent()->getQuote();
        $storeId = (int) ($quote ? $quote->getStoreId() : $method->getStore());
        if (!isset($this->offeredGroups->forStore($storeId)[$method->getGroup()])) {
            $result->setData('is_available', false);
        }
    }
}
