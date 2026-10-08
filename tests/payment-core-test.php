<?php
// Run with: php tests/payment-core-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/StatusMap.php';
require __DIR__ . '/../Model/Thb.php';
require __DIR__ . '/../Model/ExternalId.php';
require __DIR__ . '/../Model/Api/FoundPayment.php';

use Reservepay\Payment\Model\Api\FoundPayment;
use Reservepay\Payment\Model\ExternalId;
use Reservepay\Payment\Model\StatusMap;
use Reservepay\Payment\Model\Thb;

$statuses = [
    'SUCCESSFUL' => ['paid', true],
    'PARTIALLY_REFUNDED' => ['unknown', true],
    'REFUNDED' => ['unknown', true],
    'DISPUTED' => ['unknown', true],
    'PENDING' => ['open', false],
    'AUTHORIZED' => ['open', false],
    'FAILED' => ['failed', false],
    'EXPIRED' => ['failed', false],
    'REVERSED' => ['failed', false],
    'VOIDED' => ['failed', false],
    'successful' => ['paid', true],
    'refunded' => ['unknown', true],
    'SOMETHING_NEW' => ['unknown', false],
    '' => ['unknown', false],
];
foreach ($statuses as $status => [$outcome, $captured]) {
    check("status $status outcome", StatusMap::outcome($status), $outcome);
    check("status $status captured", StatusMap::captured($status), $captured);
}
check('status null outcome', StatusMap::outcome(null), 'unknown');
check('status null captured', StatusMap::captured(null), false);
check('only SUCCESSFUL pays an order', array_keys(array_filter(StatusMap::STATUSES, fn ($row) => $row[0] === 'paid')), ['SUCCESSFUL']);

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

$stored = ['prefix' => 'm2-demo-local-a1b2', 'host' => 'demo.localhost'];
check('prefix kept on the same host', ExternalId::storedPrefixForHost($stored, 'demo.localhost'), 'm2-demo-local-a1b2');
check('prefix kept, host case ignored', ExternalId::storedPrefixForHost($stored, 'Demo.LocalHost'), 'm2-demo-local-a1b2');
check('prefix regenerates on another host', ExternalId::storedPrefixForHost($stored, 'staging.demo.localhost'), null);
check('prefix regenerates from a plain string flag', ExternalId::storedPrefixForHost('m2-demo-local-a1b2', 'demo.localhost'), null);
check('prefix made when no flag', ExternalId::storedPrefixForHost(null, 'demo.localhost'), null);

$found = fn (array $overrides = []) => new FoundPayment(
    $overrides['payment_id'] ?? 'pay_1',
    array_key_exists('external_id', $overrides) ? $overrides['external_id'] : 'm2-demo-local-a1b2_order_000000007_1',
    $overrides['payment_session_id'] ?? 'pse_a',
    'SUCCESSFUL',
    100,
    'THB'
);
$attempt = ['external_id' => 'm2-demo-local-a1b2_order_000000007_1', 'payment_id' => null, 'session_id' => 'pse_a', 'created_at' => 0];
$stored = ['payment_id' => 'pay_1'] + $attempt;
check('belongs: session and external id match', $found()->belongsTo($attempt), true);
check('belongs: session matches, external id from a clone', $found(['external_id' => 'm2-clone-ffff_order_000000007_1'])->belongsTo($attempt), false);
check('belongs: session matches, no external id', $found(['external_id' => null])->belongsTo($attempt), false);
check('belongs: external id matches, other session', $found(['payment_session_id' => 'pse_b'])->belongsTo($attempt), false);
check('belongs: stored payment id, all match', $found()->belongsTo($stored), true);
check('belongs: stored payment id, other external id', $found(['external_id' => 'm2-clone-ffff_order_000000007_1'])->belongsTo($stored), false);
check('belongs: stored payment id differs', $found(['payment_id' => 'pay_2'])->belongsTo($stored), false);

done();
