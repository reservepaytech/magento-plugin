<?php

namespace Reservepay\Payment\Model;

use Reservepay\Payment\Model\Api\Client;

/**
 * The payment groups checkout offers, with their logos. The Reservepay installation is the only place a merchant
 * configures them: its settings come from the SDK's own settings endpoint and are kept for 10 minutes. Without any
 * settings, checkout still offers CARD, so a configured Reservepay never shows zero options. An inactive or
 * unconfigured Reservepay offers nothing and fetches nothing.
 */
class OfferedGroups
{
    private const SETTINGS_TTL_SECONDS = 600;

    private array $offered = [];

    public function __construct(
        private readonly Client $client,
        private readonly RemoteCache $remoteCache,
        private readonly PaymentAssets $paymentAssets,
        private readonly Config $config
    ) {
    }

    /**
     * @return array<string, array{logos: list<array{src: string, alt: string}>, more: int}> by group, in chooser order
     */
    public function forStore(int $storeId): array
    {
        return $this->offered[$storeId] ??= $this->load($storeId);
    }

    private function load(int $storeId): array
    {
        if (!$this->config->isActive($storeId) || !$this->config->isConfigured($storeId)) {
            return [];
        }
        $settings = $this->settings($storeId);
        $assets = $this->paymentAssets->forStore($storeId);
        $symbols = PaymentGroups::logoSymbols($settings, $assets['cards']);
        $offered = [];
        foreach (PaymentGroups::available($settings) ?: [PaymentGroups::DEFAULT_GROUP] as $group) {
            $offered[$group] = PaymentGroups::pickLogos($symbols[$group], $assets['logos']);
        }
        return $offered;
    }

    /**
     * @return array|null cut down to what the group rules read, null when never fetched
     */
    private function settings(int $storeId): ?array
    {
        $merchantId = $this->config->merchantId($storeId);
        $installationId = $this->config->installationId($storeId);
        $key = implode('|', [$this->config->apiBaseUrl($storeId), $merchantId, $installationId]);
        return $this->remoteCache->get('installation_settings', $key, self::SETTINGS_TTL_SECONDS, function () use ($storeId, $merchantId, $installationId) {
            $response = $this->client->retrieveInstallationSettings($storeId, $merchantId, $installationId);
            $methods = array_map(fn ($method) => [
                'name' => $method['name'] ?? null,
                'category' => $method['category'] ?? null,
                'banks' => array_map(fn ($bank) => ['name' => $bank['name'] ?? null], is_array($method['banks'] ?? null) ? $method['banks'] : []),
            ], array_filter($response['payment_methods'], 'is_array'));
            return ['value' => ['interest_bearer' => $response['interest_bearer'] ?? null, 'payment_methods' => array_values($methods)]];
        });
    }
}
