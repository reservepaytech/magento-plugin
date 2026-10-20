<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The payment/reservepay_payment settings: credentials and the Reservepay API, SDK, payment assets and dashboard
 * locations. An
 * empty location means production, because environment config such as
 * CONFIG__DEFAULT__PAYMENT__RESERVEPAY_PAYMENT__SDK_URL= stores '' and hides any default in config.xml.
 */
class Config
{
    public const DEFAULT_API_BASE_URL = 'https://api.reservepay.com/';
    public const DEFAULT_SDK_URL = 'https://sdk.reservepay.com/js/v1/reservepay.js';
    public const DEFAULT_DASHBOARD_URL = 'https://dashboard.reservepay.com';

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

    /**
     * The webhook endpoint's verification key from the Reservepay dashboard, as shown there (Base64). '' when unset.
     */
    public function webhookKey(?int $storeId = null): string
    {
        return trim($this->encryptor->decrypt($this->value('webhook_key', $storeId)));
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
     * Where the SDK's checkout helper reads the logo manifest, or '' for the SDK's own default, which sits on the
     * SDK's origin.
     */
    public function paymentAssetsUrl(?int $storeId = null): string
    {
        return $this->value('payment_assets_url', $storeId);
    }

    /**
     * The Reservepay dashboard the admin order view links to. An override must be https, or http on a local host.
     */
    public function dashboardUrl(?int $storeId = null): string
    {
        $url = $this->value('dashboard_url', $storeId);
        return self::isSafeOverride($url) ? rtrim($url, '/') : self::DEFAULT_DASHBOARD_URL;
    }

    /**
     * The payment's page in the dashboard. Always the live mode: the dashboard switches to test mode itself when the
     * payment is a test one.
     */
    public static function dashboardPaymentUrl(string $dashboardUrl, string $merchantId, string $paymentId): string
    {
        return $dashboardUrl . '/merchants/live/' . rawurlencode($merchantId)
            . '/transactions/payments/' . rawurlencode($paymentId);
    }

    public static function isSafeOverride(string $url): bool
    {
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = $host === 'localhost' || $host === 'host.docker.internal' || str_ends_with($host, '.localhost');
        return $host !== '' && ($scheme === 'https' || ($scheme === 'http' && $local));
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
            'payment/' . PaymentMethod::CODE . '/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}
