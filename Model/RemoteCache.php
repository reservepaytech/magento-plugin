<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\App\CacheInterface;

/**
 * A remote JSON value kept in Magento's cache. The last good value outlives its freshness, so an outage keeps serving
 * it, and a failure is remembered for a minute, so a down endpoint is not called on every checkout request. Only
 * forget() and cache:flush drop the last good value: the entries carry no tag.
 */
class RemoteCache
{
    private const RETRY_AFTER_SECONDS = 60;
    private const KEEP_SECONDS = 30 * 86400;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * @param callable(): array $fetch throws when the value cannot be had
     */
    public function get(string $name, string $key, int $ttl, callable $fetch): ?array
    {
        $id = $this->id($name, $key);
        $entry = json_decode((string) $this->cache->load($id), true);
        $entry = is_array($entry) ? $entry : ['value' => null, 'fresh_until' => 0];
        if ($entry['fresh_until'] > time()) {
            return $entry['value'];
        }

        try {
            $entry['value'] = $fetch();
            $entry['fresh_until'] = time() + $ttl;
        } catch (\Throwable $e) {
            $entry['fresh_until'] = time() + self::RETRY_AFTER_SECONDS;
        }
        $this->save($id, $entry);
        return $entry['value'];
    }

    /**
     * Fetches now and replaces the entry. A throw leaves the entry as it was.
     *
     * @param callable(): array $fetch
     */
    public function refresh(string $name, string $key, int $ttl, callable $fetch): array
    {
        $value = $fetch();
        $this->save($this->id($name, $key), ['value' => $value, 'fresh_until' => time() + $ttl]);
        return $value;
    }

    public function forget(string $name, string $key): void
    {
        $this->cache->remove($this->id($name, $key));
    }

    private function id(string $name, string $key): string
    {
        return 'reservepay_' . $name . '_' . sha1($key);
    }

    private function save(string $id, array $entry): void
    {
        $this->cache->save((string) json_encode($entry), $id, [], self::KEEP_SECONDS);
    }
}
