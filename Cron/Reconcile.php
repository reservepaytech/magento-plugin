<?php

namespace Reservepay\Payment\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Reservepay\Payment\Model\OrderSync;
use Reservepay\Payment\Model\PaymentGroups;

/**
 * Settles orders whose shopper never came back from the payment form, without any help from the browser, and watches
 * cancelled and held orders, and paid orders with more than one attempt, for a capture they cannot take. An order
 * takes part from its first attempt until its newest attempt is 72 hours old (OrderSync::LAST_CHECKED). Each run
 * checks one batch, least recently checked first, so a few slow orders cannot starve the rest.
 */
class Reconcile
{
    private const BATCH_SIZE = 50;
    // Held is either paid and held by hand, or unpaid after an amount mismatch, which a late capture can still hit.
    private const ALWAYS_STATES = [Order::STATE_PENDING_PAYMENT, Order::STATE_CANCELED, Order::STATE_HOLDED];
    // Closed is refunded with a credit memo.
    private const PAID_STATES = [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly OrderSync $orderSync,
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
        $outcomes = [];
        foreach ($this->nextBatch($batchSize) as $orderId => $incrementId) {
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
        }

        if ($outcomes) {
            $this->logger->info('Reservepay reconcile finished', ['orders' => $outcomes]);
        }
        return $outcomes;
    }

    /**
     * @return array<int, string> increment id per order id
     */
    private function nextBatch(int $batchSize): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->resource->getTableName('sales_order')], ['entity_id', 'increment_id'])
            ->join(['p' => $this->resource->getTableName('sales_order_payment')], 'p.parent_id = o.entity_id', [])
            ->where('p.method IN (?)', PaymentGroups::methodCodes())
            ->where('p.' . OrderSync::LAST_CHECKED . ' IS NOT NULL')
            // A paid order with a single attempt has nothing left to watch.
            ->where(
                $connection->quoteInto('o.state IN (?)', self::ALWAYS_STATES) . ' OR ('
                . $connection->quoteInto('o.state IN (?)', self::PAID_STATES)
                . " AND JSON_LENGTH(p.additional_information, '$." . OrderSync::ATTEMPTS . "') > 1)"
            )
            ->order(['p.' . OrderSync::LAST_CHECKED . ' ASC', 'o.entity_id ASC'])
            ->limit($batchSize);
        return $connection->fetchPairs($select);
    }
}
