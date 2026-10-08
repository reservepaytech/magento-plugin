<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Receives Reservepay webhooks. A webhook only says "check this order now": the payload's status is never trusted,
 * OrderSync asks find-payment, and the reconciler stays the safety net for anything missed here.
 */
class Webhook
{
    public const SIGNATURE_HEADER = 'Reservepay-Signature';
    // Payout and topup events never concern an order.
    public const PAYMENT_EVENTS = [
        'payment_authorized',
        'payment_completed',
        'payment_expired',
        'payment_voided',
        'payment_reversed',
    ];
    // Reservepay retries a delivery for well under a week.
    private const SEEN_SECONDS = 7 * 86400;

    public function __construct(
        private readonly Config $config,
        private readonly OrderSync $orderSync,
        private readonly CacheInterface $cache,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $body the request body exactly as received
     * @return int the HTTP status to answer with
     */
    public function receive(string $body, string $signature): int
    {
        $key = $this->config->webhookKey();
        if ($key === '') {
            $this->logger->debug('Reservepay webhook ignored, no verification key is set');
            return 503;
        }
        if (!self::signatureValid($body, $signature, $key)) {
            $this->logger->warning('Reservepay webhook refused, signature missing or wrong');
            return 401;
        }
        $event = json_decode($body, true);
        if (!is_array($event) || !is_string($event['event_id'] ?? null) || $event['event_id'] === '') {
            $this->logger->warning('Reservepay webhook refused, body is not an event');
            return 400;
        }

        $context = [
            'event' => $event['event'] ?? null,
            'event_id' => $event['event_id'],
            'external_id' => $event['external_id'] ?? null,
            'payment_id' => $event['payment_id'] ?? null,
            'status' => $event['status'] ?? null,
        ];
        if (!in_array($event['event'] ?? null, self::PAYMENT_EVENTS, true)) {
            $this->logger->info('Reservepay webhook ignored, not a payment event', $context);
            return 200;
        }
        if ($this->seen($event['event_id'])) {
            $this->logger->info('Reservepay webhook ignored, event already handled', $context);
            return 200;
        }

        $orderId = is_string($event['external_id'] ?? null) ? $this->orderIdFor($event['external_id']) : null;
        if ($orderId === null) {
            $this->logger->info('Reservepay webhook matches no order', $context);
        } else {
            try {
                $outcome = $this->orderSync->sync($orderId, OrderSync::TRIGGER_WEBHOOK);
            } catch (\Throwable $e) {
                // Not remembered, so Reservepay's retry gets another go.
                $this->logger->error('Reservepay webhook could not check its order', [
                    ...$context,
                    'error' => $e->getMessage(),
                ]);
                return 500;
            }
            $this->logger->info('Reservepay webhook checked its order', [...$context, 'outcome' => $outcome]);
        }
        $this->remember($event['event_id']);
        return 200;
    }

    public static function signatureValid(string $body, string $signature, string $key): bool
    {
        $rawKey = base64_decode($key, true);
        if ($rawKey === false
            || $rawKey === ''
            || !preg_match('/^hmac_sha256=([0-9a-fA-F]{64})$/', $signature, $match)
        ) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $body, $rawKey), strtolower($match[1]));
    }

    public function seen(string $eventId): bool
    {
        return $this->cache->load($this->cacheId($eventId)) !== false;
    }

    /**
     * Not atomic with seen(): two copies arriving at once both sync, which OrderSync makes harmless.
     */
    public function remember(string $eventId): void
    {
        $this->cache->save('1', $this->cacheId($eventId), [], self::SEEN_SECONDS);
    }

    /**
     * The Reservepay order that has an attempt with this external id, or null.
     */
    private function orderIdFor(string $externalId): ?int
    {
        $ref = ExternalId::orderRef($externalId);
        if ($ref === null) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $refMatches = $connection->quoteInto('o.increment_id = ?', $ref);
        if (ctype_digit($ref)) {
            $refMatches .= $connection->quoteInto(' OR o.entity_id = ?', (int) $ref);
        }
        $select = $connection->select()
            ->from(['o' => $this->resource->getTableName('sales_order')], ['entity_id'])
            ->join(
                ['p' => $this->resource->getTableName('sales_order_payment')],
                'p.parent_id = o.entity_id',
                ['additional_information']
            )
            ->where('p.method IN (?)', PaymentGroups::methodCodes())
            ->where($refMatches);
        foreach ($connection->fetchPairs($select) as $orderId => $info) {
            $attempts = json_decode((string) $info, true)[OrderSync::ATTEMPTS] ?? null;
            if (is_array($attempts) && in_array($externalId, array_column($attempts, 'external_id'), true)) {
                return (int) $orderId;
            }
        }
        return null;
    }

    private function cacheId(string $eventId): string
    {
        return 'reservepay_webhook_' . sha1($eventId);
    }
}
