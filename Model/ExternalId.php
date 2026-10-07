<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\Store;

/**
 * Payment attempt ids: "<prefix>_order_<increment id>_<n>", with the prefix "m2-<host>-<4 hex>", the same shape as
 * the WooCommerce plugin's "wc-..." prefix. The prefix is made once per Magento database and kept in the flag table,
 * so a reset demo store or a second store on the same Reservepay installation never reuses an earlier store's ids.
 */
class ExternalId
{
    public const MAX_LENGTH = 40;
    private const HOST_LENGTH = 10;
    private const FLAG = 'reservepay_external_id_prefix';
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly LockManagerInterface $lockManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function forAttempt(string $incrementId, int $orderId, int $attempt): string
    {
        return self::format($this->prefix(), $incrementId, $orderId, $attempt);
    }

    /**
     * Uses the order entity id instead when a long custom increment id would push the id past MAX_LENGTH.
     */
    public static function format(string $prefix, string $incrementId, int $orderId, int $attempt): string
    {
        $externalId = $prefix . '_order_' . $incrementId . '_' . $attempt;
        return strlen($externalId) <= self::MAX_LENGTH ? $externalId : $prefix . '_order_' . $orderId . '_' . $attempt;
    }

    /**
     * Starts with "m2-": Reservepay reads any id that starts with "pay" as its own payment id, not as an external_id.
     */
    public static function makePrefix(string $host, string $random): string
    {
        $host = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($host)), '-'), 0, self::HOST_LENGTH), '-');
        return 'm2-' . ($host !== '' ? $host . '-' : '') . $random;
    }

    private function prefix(): string
    {
        $prefix = $this->flagManager->getFlagData(self::FLAG);
        if (is_string($prefix) && $prefix !== '') {
            return $prefix;
        }
        // Under a lock, so two first payments at once cannot each store their own prefix.
        if (!$this->lockManager->lock(self::FLAG, self::LOCK_TIMEOUT_SECONDS)) {
            throw new \RuntimeException('Could not lock the Reservepay external id prefix');
        }
        try {
            $prefix = $this->flagManager->getFlagData(self::FLAG);
            if (!is_string($prefix) || $prefix === '') {
                $host = (string) parse_url((string) $this->scopeConfig->getValue(Store::XML_PATH_UNSECURE_BASE_URL), PHP_URL_HOST);
                $prefix = self::makePrefix($host, bin2hex(random_bytes(2)));
                $this->flagManager->saveFlag(self::FLAG, $prefix);
            }
            return $prefix;
        } finally {
            $this->lockManager->unlock(self::FLAG);
        }
    }
}
