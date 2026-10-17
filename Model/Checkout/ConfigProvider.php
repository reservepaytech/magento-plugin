<?php

namespace Reservepay\Payment\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\InstallationSettings;
use Reservepay\Payment\Model\PaymentCategories;

/**
 * window.checkoutConfig.payment.reservepay: every category's method code, the installation settings and the SDK
 * location. The checkout JS asks the SDK which categories to show. An inactive or unconfigured Reservepay gets no
 * categories, so checkout neither fetches the settings nor loads the SDK.
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
            return ['payment' => ['reservepay' => ['categories' => []]]];
        }
        $categories = [];
        foreach (PaymentCategories::CODES as $category => $code) {
            $categories[] = ['code' => $code, 'category' => $category];
        }
        return ['payment' => ['reservepay' => [
            'categories' => $categories,
            'settings' => $this->installationSettings->forStore($storeId),
            'sdkUrl' => $this->config->sdkUrl($storeId),
            'paymentAssetsUrl' => $this->config->paymentAssetsUrl($storeId) ?: null,
        ]]];
    }
}
