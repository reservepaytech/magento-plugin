<?php

namespace Reservepay\Payment\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\InstallationSettings;

/**
 * window.checkoutConfig.payment.reservepay: the installation settings, the SDK location and the store locale, which the
 * checkout JS uses to list the installation's payment categories and their logos under the payment method. An inactive
 * or unconfigured Reservepay gets nothing, so checkout neither fetches the settings nor loads the SDK.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly InstallationSettings $installationSettings,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver
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
            'locale' => $this->localeResolver->getLocale(),
        ]]];
    }
}
