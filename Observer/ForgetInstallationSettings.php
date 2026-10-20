<?php

namespace Reservepay\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Reservepay\Payment\Model\InstallationSettings;

/**
 * Saving the payment settings drops every store view's cached installation settings, so checkout fetches them again
 * on its next visit, even when nothing changed.
 */
class ForgetInstallationSettings implements ObserverInterface
{
    public function __construct(
        private readonly InstallationSettings $installationSettings,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->installationSettings->forget((int) $store->getId());
        }
    }
}
