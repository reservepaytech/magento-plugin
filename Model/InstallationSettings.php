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
        return $this->loaded[$storeId] ??= $this->load($storeId);
    }

    private function load(int $storeId): ?array
    {
        $merchantId = $this->config->merchantId($storeId);
        $installationId = $this->config->installationId($storeId);
        $key = implode('|', [$this->config->apiBaseUrl($storeId), $merchantId, $installationId]);
        return $this->remoteCache->get('installation_settings', $key, self::TTL_SECONDS, function () use ($storeId, $merchantId, $installationId) {
            $response = $this->client->retrieveInstallationSettings($storeId, $merchantId, $installationId);
            $methods = array_map(fn ($method) => [
                'name' => $method['name'] ?? null,
                'category' => $method['category'] ?? null,
                'banks' => array_map(fn ($bank) => ['name' => $bank['name'] ?? null], is_array($method['banks'] ?? null) ? $method['banks'] : []),
            ], array_filter($response['payment_methods'], 'is_array'));
            return ['interest_bearer' => $response['interest_bearer'] ?? null, 'payment_methods' => array_values($methods)];
        });
    }
}
