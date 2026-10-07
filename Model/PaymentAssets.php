<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Logos from the SDK's payment assets manifest, fetched on the server and kept for a day. The browser only loads the
 * images. No logo is bundled with the module, so a missing manifest or symbol just leaves the text label.
 */
class PaymentAssets
{
    private const TTL_SECONDS = 86400;
    private const TIMEOUT_SECONDS = 5;

    private array $loaded = [];

    public function __construct(
        private readonly RemoteCache $remoteCache,
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{logos: array<string, array{src: string, alt: string}>, cards: string[]} logos by normalized
     *         symbol, and the card brand symbols; both empty when the manifest is unavailable
     */
    public function forStore(int $storeId): array
    {
        $url = $this->config->paymentAssetsUrl($storeId);
        return $this->loaded[$url] ??= $this->remoteCache->get('payment_assets', $url, self::TTL_SECONDS, fn (?string $etag) => $this->fetch($url, $etag))
            ?? ['logos' => [], 'cards' => []];
    }

    private function fetch(string $url, ?string $etag): ?array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        // Reservepay's firewall refuses requests without a User-Agent, and Magento's client sends none by default.
        $curl->addHeader('User-Agent', ClientInfo::TOKEN);
        if ($etag !== null && $etag !== '') {
            $curl->addHeader('If-None-Match', $etag);
        }
        try {
            $curl->get($url);
        } catch (\Exception $e) {
            $this->logger->warning('Reservepay payment assets manifest unreachable, checkout shows text labels', ['url' => $url, 'error' => $e->getMessage()]);
            throw $e;
        }
        $status = $curl->getStatus();
        if ($status === 304) {
            return null;
        }
        $entries = $status === 200 ? json_decode($curl->getBody(), true) : null;
        if (!is_array($entries)) {
            $this->logger->warning('Reservepay payment assets manifest unavailable, checkout shows text labels', ['url' => $url, 'http_status' => $status]);
            throw new \RuntimeException('Payment assets manifest unavailable');
        }

        $origin = Config::origin($url);
        $logos = [];
        $cards = [];
        foreach ($entries as $entry) {
            $symbol = $entry['symbol'] ?? null;
            $src = $this->sameOriginUrl($entry['logo']['vector'] ?? null, $origin);
            if (!is_string($symbol) || $src === null) {
                continue;
            }
            $logos[PaymentGroups::normalizeSymbol($symbol)] = [
                'src' => $src,
                'alt' => is_string($entry['name'] ?? null) ? $entry['name'] : $symbol,
            ];
            if (empty($entry['code']) && ($entry['kind'] ?? null) === 'card') {
                $cards[] = $symbol;
            }
        }
        return ['value' => ['logos' => $logos, 'cards' => $cards], 'etag' => $this->header($curl->getHeaders(), 'etag')];
    }

    /**
     * Only the manifest's origin is in the page's img-src (Csp\SdkPolicyCollector). A logo elsewhere would be blocked,
     * so it counts as missing and its group keeps the text label.
     */
    private function sameOriginUrl(mixed $src, string $origin): ?string
    {
        if (!is_string($src) || $src === '' || $origin === '') {
            return null;
        }
        if ($src[0] === '/' && !str_starts_with($src, '//')) {
            return $origin . $src;
        }
        return Config::origin($src) === $origin ? $src : null;
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }
        return null;
    }
}
