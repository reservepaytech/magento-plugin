<?php

namespace Reservepay\Payment\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Reservepay\Payment\Model\OfferedGroups;
use Reservepay\Payment\Model\PaymentGroups;

/**
 * window.checkoutConfig.payment.reservepay.groups: one entry per offered group, which the checkout JS turns into one
 * payment method renderer each.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly OfferedGroups $offeredGroups,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getConfig()
    {
        $groups = [];
        foreach ($this->offeredGroups->forStore((int) $this->storeManager->getStore()->getId()) as $group => $logos) {
            $groups[] = ['code' => PaymentGroups::CODES[$group], 'group' => $group] + $logos;
        }
        return ['payment' => ['reservepay' => ['groups' => $groups]]];
    }
}
