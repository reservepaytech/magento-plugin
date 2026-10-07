<?php
// Needs a Magento install with this module, for the real crypt key and Magento classes. From the Magento root:
//   php app/code/Reservepay/Payment/tests/order-token-test.php
// Orders are kept in memory. Nothing is written to the database.
require __DIR__ . '/check.php';

$root = __DIR__;
while (!is_file($root . '/app/bootstrap.php')) {
    if (dirname($root) === $root) {
        fwrite(STDERR, "No Magento install above " . __DIR__ . "\n");
        exit(2);
    }
    $root = dirname($root);
}
require $root . '/app/bootstrap.php';

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Reservepay\Payment\Model\OrderToken;

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(Area::AREA_CRONTAB);

$order = function (int $entityId, string $incrementId, int $storeId, int $quoteId, string $method) use (
    $objectManager
): Order {
    $order = $objectManager->create(Order::class);
    $order->setEntityId($entityId)->setIncrementId($incrementId)->setStoreId($storeId)->setQuoteId($quoteId);
    $order->setData(OrderInterface::PAYMENT, $objectManager->create(Payment::class)->setMethod($method));
    return $order;
};
$a = $order(1, '100000001', 1, 11, 'reservepay_card');
$b = $order(2, '100000002', 1, 12, 'reservepay_installment');
$checkmo = $order(3, '100000003', 1, 13, 'checkmo');
// Increment ids are only unique per store, so a second store can repeat one.
$otherStore = $order(4, '100000001', 2, 14, 'reservepay_card');

$repository = new class ([$a, $b, $checkmo, $otherStore]) implements OrderRepositoryInterface {
    public function __construct(private readonly array $orders)
    {
    }

    public function get($id)
    {
        foreach ($this->orders as $order) {
            if ((int) $order->getEntityId() === $id) {
                return $order;
            }
        }
        throw new NoSuchEntityException(__('No such order'));
    }

    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria)
    {
        throw new \LogicException('not used');
    }

    public function delete(OrderInterface $entity)
    {
        throw new \LogicException('not used');
    }

    public function save(OrderInterface $entity)
    {
        throw new \LogicException('not used');
    }
};
$tokens = $objectManager->create(OrderToken::class, ['orderRepository' => $repository]);

$token = $tokens->issue($a);
[$orderId, $expires, $signature] = explode('.', $token);
check('token names its order', $tokens->orderFor($token), $a);
check('token is "<entity id>.<expires>.<signature>"', $orderId, '1');
check('expires in 24 hours', abs((int) $expires - (time() + 86400)) <= 5, true);
$otherToken = $tokens->issue($otherStore);
check('same increment id in another store names that order', $tokens->orderFor($otherToken), $otherStore);

$flipped = $signature;
$flipped[0] = $flipped[0] === 'a' ? 'b' : 'a';
check('changed signature', $tokens->orderFor("$orderId.$expires.$flipped"), null);
check('another order with this signature', $tokens->orderFor("2.$expires.$signature"), null);
check('the other store\'s order with this signature', $tokens->orderFor("4.$expires.$signature"), null);
check('expiry pushed later', $tokens->orderFor("$orderId." . ((int) $expires + 1) . ".$signature"), null);
check('expiry in the past', $tokens->orderFor("$orderId." . (time() - 1) . ".$signature"), null);
$a->setQuoteId(99);
check('same order, other quote', $tokens->orderFor($token), null);
$a->setQuoteId(11);
check('valid again with the right quote', $tokens->orderFor($token), $a);
check('order paid with another method', $tokens->orderFor($tokens->issue($checkmo)), null);
check('unknown order', $tokens->orderFor("9.$expires.$signature"), null);
$malformedTokens = ['', 'abc', 'a.b', '..', "$orderId.soon.$signature", "1.$expires.$signature.x", "-1.$expires.$signature"];
foreach ($malformedTokens as $malformed) {
    check("malformed \"$malformed\"", $tokens->orderFor($malformed), null);
}

done();
