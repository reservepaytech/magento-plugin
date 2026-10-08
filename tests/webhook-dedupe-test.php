<?php
// Needs a Magento install with this module, for Magento's cache. From the Magento root:
//   php app/code/Reservepay/Payment/tests/webhook-dedupe-test.php
// Writes one cache entry under a random event id, which expires on its own.
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

use Magento\Framework\App\Bootstrap;
use Reservepay\Payment\Model\Webhook;

$webhook = Bootstrap::create(BP, $_SERVER)->getObjectManager()->get(Webhook::class);
$eventId = 'evt_test_' . bin2hex(random_bytes(8));

check('new event is not seen', $webhook->seen($eventId), false);
$webhook->remember($eventId);
check('remembered event is seen', $webhook->seen($eventId), true);
check('another event is not seen', $webhook->seen($eventId . 'x'), false);

done();
