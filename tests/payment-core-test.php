<?php
// Run with: php tests/payment-core-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/StatusMap.php';
require __DIR__ . '/../Model/MinorUnits.php';
require __DIR__ . '/../Model/ExternalId.php';

use Reservepay\Payment\Model\ExternalId;
use Reservepay\Payment\Model\MinorUnits;
use Reservepay\Payment\Model\StatusMap;

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

check('THB 1234.56', MinorUnits::fromMajor('1234.56', 'THB'), 123456);
check('THB float 0.29 rounds, not truncates', MinorUnits::fromMajor(0.29, 'THB'), 29);
check('thb lowercase', MinorUnits::fromMajor('1', 'thb'), 100);
check('JPY has no minor unit', MinorUnits::fromMajor('1500', 'JPY'), 1500);
check('KRW has no minor unit', MinorUnits::fromMajor('1500.4', 'KRW'), 1500);
check('KWD has three', MinorUnits::fromMajor('1.234', 'KWD'), 1234);
check('BHD has three', MinorUnits::fromMajor('0.5', 'BHD'), 500);

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
