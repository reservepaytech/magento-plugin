<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\App\CacheInterface;

/**
 * A remote JSON value kept in Magento's cache. The last good value outlives its freshness, so an outage keeps serving
 * it, and a failure is remembered for a minute, so a down endpoint is not called on every checkout request. Only
 * cache:flush drops the last good value: the entries carry no tag.
 */
class RemoteCache
{
    private const RETRY_AFTER_SECONDS = 60;
    private const KEEP_SECONDS = 30 * 86400;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * @param callable(?string): ?array{value: array, etag?: string} $fetch gets the last ETag, returns null when not
     *        modified, throws when the value cannot be had
     */
    public function get(string $name, string $key, int $ttl, callable $fetch): ?array
    {
        $id = 'reservepay_' . $name . '_' . sha1($key);
        $entry = json_decode((string) $this->cache->load($id), true);
        $entry = is_array($entry) ? $entry : ['value' => null, 'etag' => null, 'fresh_until' => 0];
        if ($entry['fresh_until'] > time()) {
            return $entry['value'];
        }

        try {
            $result = $fetch($entry['value'] !== null ? $entry['etag'] : null);
            if ($result !== null) {
                $entry['value'] = $result['value'];
                $entry['etag'] = $result['etag'] ?? null;
            }
            $entry['fresh_until'] = time() + $ttl;
        } catch (\Throwable $e) {
            $entry['fresh_until'] = time() + self::RETRY_AFTER_SECONDS;
        }
        $this->cache->save((string) json_encode($entry), $id, [], self::KEEP_SECONDS);
        return $entry['value'];
    }
}
