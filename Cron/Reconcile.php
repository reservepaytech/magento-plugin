<?php

namespace Reservepay\Payment\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Reservepay\Payment\Model\Api\Client;
use Reservepay\Payment\Model\OrderSync;
use Reservepay\Payment\Model\PaymentMethod;

/**
 * Settles orders whose shopper never came back from the payment form, without any help from the browser, and watches
 * cancelled and held orders, and paid orders with more than one attempt, for a capture they cannot take. An order
 * takes part from its first attempt until nothing on it can change any more (OrderSync::LAST_CHECKED). Each run
 * checks one batch, least recently checked first, so a few slow orders cannot starve the rest. Orders whose newest
 * attempt is less than an hour old are checked every run, older ones once an hour.
 */
class Reconcile
{
    private const BATCH_SIZE = 50;
    private const RECENT_SECONDS = 3600;
    private const OLDER_GAP_SECONDS = 3600;
    // No new order is started after this long, so a slow Reservepay holds a cron worker for about this long.
    private const TIME_BUDGET_SECONDS = 20;
    // Held is either paid and held by hand, or unpaid after an amount mismatch, which a late capture can still hit.
    private const ALWAYS_STATES = [Order::STATE_PENDING_PAYMENT, Order::STATE_CANCELED, Order::STATE_HOLDED];
    // Closed is refunded with a credit memo.
    private const PAID_STATES = [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly OrderSync $orderSync,
        private readonly Client $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): array
    {
        return $this->run(self::BATCH_SIZE);
    }

    /**
     * @return array<string, string> outcome per order increment id
     */
    public function run(int $batchSize): array
    {
        $started = microtime(true);
        $unavailable = $this->client->unavailableCount();
        $outcomes = [];
        foreach ($this->nextBatch($batchSize, time()) as $orderId => $incrementId) {
            try {
                $outcomes[$incrementId] = $this->orderSync->sync((int) $orderId, OrderSync::TRIGGER_RECONCILER);
            } catch (\Throwable $e) {
                $outcomes[$incrementId] = 'error';
                $this->logger->error('Reservepay could not check an order', [
                    'order' => $incrementId,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                // Sent to the back of the queue even when it failed, so it cannot block the batch. An order that sync
                // just took out of the reconciler stays out.
                $this->resource->getConnection()->update(
                    $this->resource->getTableName('sales_order_payment'),
                    [OrderSync::LAST_CHECKED => gmdate('Y-m-d H:i:s')],
                    ['parent_id = ?' => $orderId, OrderSync::LAST_CHECKED . ' IS NOT NULL']
                );
            }
            // The rest go first next run, least recently checked first, so none is skipped for good.
            if ($this->client->unavailableCount() > $unavailable) {
                $this->logger->warning('Reservepay unavailable, reconcile stopped for this run');
                break;
            }
            if (microtime(true) - $started >= self::TIME_BUDGET_SECONDS) {
                $this->logger->warning('Reservepay reconcile out of time, stopped for this run');
                break;
            }
        }

        if ($outcomes) {
            $this->logger->info('Reservepay reconcile finished', ['orders' => $outcomes]);
        }
        return $outcomes;
    }

    /**
     * Older orders get up to half of the batch, so a busy hour of new orders cannot starve them.
     *
     * @return array<int, string> increment id per order id
     */
    private function nextBatch(int $batchSize, int $now): array
    {
        // Attempts are not in order of created_at: a re-initiated attempt starts its clock again.
        $newest = sprintf(
            "COALESCE((SELECT MAX(a.created_at) FROM JSON_TABLE(p.additional_information, '$.%s[*]'"
            . " COLUMNS (created_at BIGINT PATH '$.created_at')) a), 0)",
            OrderSync::ATTEMPTS
        );
        $recentFrom = $now - self::RECENT_SECONDS;
        // A limit of 0 is no limit to Select.
        $older = $batchSize < 2 ? [] : $this->resource->getConnection()->fetchPairs(
            $this->watched(intdiv($batchSize, 2))
                ->where("$newest < ?", $recentFrom)
                ->where('p.' . OrderSync::LAST_CHECKED . ' <= ?', gmdate('Y-m-d H:i:s', $now - self::OLDER_GAP_SECONDS))
        );
        $recent = $this->resource->getConnection()->fetchPairs(
            $this->watched($batchSize - count($older))->where("$newest >= ?", $recentFrom)
        );
        return self::interleave($recent, $older);
    }

    /**
     * Alternates the two lists, so a run cut short still reaches both.
     *
     * @param array<int, string> $first
     * @param array<int, string> $second
     * @return array<int, string>
     */
    public static function interleave(array $first, array $second): array
    {
        $first = array_chunk($first, 1, true);
        $second = array_chunk($second, 1, true);
        $merged = [];
        $rounds = max(count($first), count($second));
        for ($i = 0; $i < $rounds; $i++) {
            $merged += ($first[$i] ?? []) + ($second[$i] ?? []);
        }
        return $merged;
    }

    private function watched(int $limit): Select
    {
        $connection = $this->resource->getConnection();
        return $connection->select()
            ->from(['o' => $this->resource->getTableName('sales_order')], ['entity_id', 'increment_id'])
            ->join(['p' => $this->resource->getTableName('sales_order_payment')], 'p.parent_id = o.entity_id', [])
            ->where('p.method = ?', PaymentMethod::CODE)
            ->where('p.' . OrderSync::LAST_CHECKED . ' IS NOT NULL')
            // A paid order with a single attempt has nothing left to watch.
            ->where(
                $connection->quoteInto('o.state IN (?)', self::ALWAYS_STATES) . ' OR ('
                . $connection->quoteInto('o.state IN (?)', self::PAID_STATES)
                . " AND JSON_LENGTH(p.additional_information, '$." . OrderSync::ATTEMPTS . "') > 1)"
            )
            ->order(['p.' . OrderSync::LAST_CHECKED . ' ASC', 'o.entity_id ASC'])
            ->limit($limit);
    }
}
