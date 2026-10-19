<?php

namespace Reservepay\Payment\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\InstallationSettings;

/**
 * window.checkoutConfig.payment.reservepay: the installation settings and the SDK location, which the checkout JS uses
 * to decorate the payment method with the logos of the installation's payment methods. An inactive or unconfigured
 * Reservepay gets nothing, so checkout neither fetches the settings nor loads the SDK.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly InstallationSettings $installationSettings,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getConfig()
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isActive($storeId) || !$this->config->isConfigured($storeId)) {
            return [];
        }
        return ['payment' => ['reservepay' => [
            'settings' => $this->installationSettings->forStore($storeId),
            'sdkUrl' => $this->config->sdkUrl($storeId),
            'paymentAssetsUrl' => $this->config->paymentAssetsUrl($storeId) ?: null,
        ]]];
    }
}
