<?php
// Run with: php tests/payment-core-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/StatusMap.php';
require __DIR__ . '/../Model/Thb.php';
require __DIR__ . '/../Model/ExternalId.php';

use Reservepay\Payment\Model\ExternalId;
use Reservepay\Payment\Model\StatusMap;
use Reservepay\Payment\Model\Thb;

$statuses = [
    'SUCCESSFUL' => 'paid', 'PARTIALLY_REFUNDED' => 'paid', 'REFUNDED' => 'paid', 'DISPUTED' => 'paid',
    'PENDING' => 'open', 'AUTHORIZED' => 'open',
    'FAILED' => 'failed', 'EXPIRED' => 'failed', 'REVERSED' => 'failed', 'VOIDED' => 'failed',
    'successful' => 'paid', 'SOMETHING_NEW' => 'unknown', '' => 'unknown',
];
foreach ($statuses as $status => $expected) {
    check("status $status", StatusMap::outcome($status), $expected);
}
check('status null', StatusMap::outcome(null), 'unknown');

check('paid beats everything', StatusMap::aggregate(['failed', 'unknown', 'open', 'paid']), 'paid');
check('open beats unknown and failed', StatusMap::aggregate(['failed', 'unknown', 'open']), 'open');
check('unknown beats failed', StatusMap::aggregate(['failed', 'unknown']), 'unknown');
check('failed only when every attempt failed', StatusMap::aggregate(['failed', 'failed']), 'failed');
check('no attempts', StatusMap::aggregate([]), 'unknown');

check('1234.56 baht in satang', Thb::satang('1234.56'), 123456);
check('float 0.29 rounds, not truncates', Thb::satang(0.29), 29);
check('whole baht', Thb::satang('1'), 100);

check('prefix from host and random', ExternalId::makePrefix('demo.localhost', 'a1b2'), 'm2-demo-local-a1b2');
check('host cut to 10 characters, no trailing dash', ExternalId::makePrefix('www.my-cat-shop.co.th', 'a1b2'), 'm2-www-my-cat-a1b2');
check('host lowercased and symbols collapsed', ExternalId::makePrefix('Pay_Shop.EXAMPLE', 'a1b2'), 'm2-pay-shop-e-a1b2');
check('no host', ExternalId::makePrefix('', 'a1b2'), 'm2-a1b2');
check('never starts with "pay"', str_starts_with(ExternalId::makePrefix('payments.example', 'a1b2'), 'pay'), false);
check('attempt id format', ExternalId::format('m2-demo-local-a1b2', '000000035', 35, 2), 'm2-demo-local-a1b2_order_000000035_2');
$longest = ExternalId::makePrefix('averyveryverylonghost.example', 'ffff');
check('typical id fits 40', strlen(ExternalId::format($longest, '2000000001', 1, 99)), 38);
check('too long increment id falls back to the entity id', ExternalId::format($longest, 'STORE-2026-0000000001', 4711, 1), 'm2-averyveryv-ffff_order_4711_1');

done();
