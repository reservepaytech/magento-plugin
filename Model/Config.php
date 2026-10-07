<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The payment/reservepay_payment settings that every payment group shares: credentials and the Reservepay API, SDK and
 * payment assets locations. An empty location means production, because environment config such as
 * CONFIG__DEFAULT__PAYMENT__RESERVEPAY_PAYMENT__SDK_URL= stores '' and hides any default in config.xml.
 */
class Config
{
    public const DEFAULT_API_BASE_URL = 'https://api.reservepay.com/';
    public const DEFAULT_SDK_URL = 'https://sdk.reservepay.com/js/v1/reservepay.js';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->value('active', $storeId);
    }

    public function isConfigured(?int $storeId = null): bool
    {
        return $this->merchantId($storeId) !== ''
            && $this->installationId($storeId) !== ''
            && $this->apiKey($storeId) !== '';
    }

    public function merchantId(?int $storeId = null): string
    {
        return $this->value('merchantid', $storeId);
    }

    public function installationId(?int $storeId = null): string
    {
        return $this->value('installationid', $storeId);
    }

    /**
     * @return string '' when unset, and also when the crypt key does not match: decrypt() returns '' instead of throwing
     */
    public function apiKey(?int $storeId = null): string
    {
        return trim($this->encryptor->decrypt($this->value('apikey', $storeId)));
    }

    public function apiBaseUrl(?int $storeId = null): string
    {
        return $this->value('api_base_url', $storeId) ?: self::DEFAULT_API_BASE_URL;
    }

    public function sdkUrl(?int $storeId = null): string
    {
        return $this->value('sdk_url', $storeId) ?: self::DEFAULT_SDK_URL;
    }

    /**
     * The SDK's logo manifest. By default it sits on the SDK's origin, like the SDK's own fetch of it.
     */
    public function paymentAssetsUrl(?int $storeId = null): string
    {
        return $this->value('payment_assets_url', $storeId)
            ?: self::origin($this->sdkUrl($storeId)) . '/static/payment-assets/manifest.json';
    }

    /**
     * @return string "scheme://host[:port]", or '' when the URL has no scheme or host
     */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function value(string $field, ?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(
            'payment/' . PaymentGroups::SETTINGS_CODE . '/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}
