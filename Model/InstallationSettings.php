<?php

namespace Reservepay\Payment\Model;

use Reservepay\Payment\Model\Api\Client;

/**
 * The Reservepay installation's settings for checkout, from the SDK's own settings endpoint, kept for 10 minutes. The
 * server fetches them because that endpoint only accepts requests from the SDK's origin. Checkout embeds them for the
 * SDK's retrievePaymentCategoryOptions(), cut down to what it reads.
 */
class InstallationSettings
{
    private const TTL_SECONDS = 600;
    private const CACHE_NAME = 'installation_settings';

    private array $loaded = [];

    public function __construct(
        private readonly Client $client,
        private readonly RemoteCache $remoteCache,
        private readonly Config $config
    ) {
    }

    /**
     * @return array|null null when never fetched
     */
    public function forStore(int $storeId): ?array
    {
        return $this->loaded[$storeId] ??= $this->remoteCache->get(
            self::CACHE_NAME,
            $this->key($storeId),
            self::TTL_SECONDS,
            fn () => $this->fetch($storeId)
        );
    }

    /**
     * Fetches now and replaces the cached settings. Throws when Reservepay cannot answer, and the cache keeps what it
     * had.
     */
    public function refresh(int $storeId): array
    {
        return $this->loaded[$storeId] = $this->remoteCache->refresh(
            self::CACHE_NAME,
            $this->key($storeId),
            self::TTL_SECONDS,
            fn () => $this->fetch($storeId)
        );
    }

    /**
     * @return string[] the payment method names, in the settings' order, each once
     */
    public static function methodNames(array $settings): array
    {
        return array_values(array_unique(array_filter(
            array_column($settings['payment_methods'] ?? [], 'name'),
            fn ($name) => is_string($name) && $name !== ''
        )));
    }

    public function fetchedAt(int $storeId): ?int
    {
        return $this->remoteCache->fetchedAt(self::CACHE_NAME, $this->key($storeId));
    }

    public function forget(int $storeId): void
    {
        unset($this->loaded[$storeId]);
        $this->remoteCache->forget(self::CACHE_NAME, $this->key($storeId));
    }

    private function key(int $storeId): string
    {
        return implode('|', [
            $this->config->apiBaseUrl($storeId),
            $this->config->merchantId($storeId),
            $this->config->installationId($storeId),
        ]);
    }

    private function fetch(int $storeId): array
    {
        $response = $this->client->retrieveInstallationSettings(
            $storeId,
            $this->config->merchantId($storeId),
            $this->config->installationId($storeId)
        );
        $methods = array_map(fn ($method) => [
            'name' => $method['name'] ?? null,
            'category' => $method['category'] ?? null,
            'banks' => array_map(fn ($bank) => ['name' => $bank['name'] ?? null], is_array($method['banks'] ?? null) ? $method['banks'] : []),
        ], array_filter($response['payment_methods'], 'is_array'));
        return ['interest_bearer' => $response['interest_bearer'] ?? null, 'payment_methods' => array_values($methods)];
    }
}
