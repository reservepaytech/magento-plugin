<?php
// Run with: php tests/payment-core-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/StatusMap.php';
require __DIR__ . '/../Model/Thb.php';
require __DIR__ . '/../Model/ExternalId.php';
require __DIR__ . '/../Model/Api/FoundPayment.php';
require __DIR__ . '/../Model/OrderSync.php';
require __DIR__ . '/../Model/Config.php';

use Reservepay\Payment\Model\Api\FoundPayment;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\ExternalId;
use Reservepay\Payment\Model\OrderSync;
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

$payment = fn (string $status, ?int $expiresAt = null) => new FoundPayment('pay_1', 'x', 'pse_1', $status, 100, 'THB', null, null, $expiresAt);
foreach (['PENDING' => true, 'AUTHORIZED' => true, 'SUCCESSFUL' => false, 'FAILED' => false, 'EXPIRED' => false, 'VOIDED' => false, 'REFUNDED' => false, 'SOMETHING_NEW' => false] as $status => $resumes) {
    check("$status payment with no expiry resumable", $payment($status)->resumable(1000), $resumes);
}
check('open payment before its expiry is resumable', $payment('PENDING', 1001)->resumable(1000), true);
check('open payment at its expiry is not resumable', $payment('PENDING', 1000)->resumable(1000), false);
$thai = fn (string $text, mixed ...$args) => strtr(['Installment' => 'ผ่อนชำระ', '%1 months' => '%1 เดือน'][$text] ?? $text, ['%1' => $args[0] ?? '']);
$paidWith = fn (array $fields) => FoundPayment::fromResponse(['payment_id' => 'pay_1', 'status' => 'SUCCESSFUL', 'amount' => 1, 'currency' => 'THB'] + $fields)->paidWith($thai);
$card = ['payment_method' => 'CARD', 'payment_method_display_name' => 'Card'];
check('paid with: card network and last digits', $paidWith($card + ['card_network' => 'visa', 'card_last_digits' => '02']), 'Visa •• 02');
check('paid with: network names', array_map(fn ($n) => $paidWith(['card_network' => $n, 'card_last_digits' => '11']), ['mastercard', 'JCB', 'amex', 'unionpay']), ['Mastercard •• 11', 'JCB •• 11', 'American Express •• 11', 'UnionPay •• 11']);
check('paid with: unknown network uses the display name', $paidWith($card + ['card_network' => 'discover', 'card_last_digits' => '02']), 'Card •• 02');
check('paid with: card without last digits', $paidWith($card + ['card_network' => 'visa', 'card_last_digits' => null]), 'Visa');
check('paid with: installment bank and term, translated', $paidWith(['payment_method' => 'INSTALLMENT', 'payment_method_display_name' => 'Installment', 'card_network' => 'visa', 'card_last_digits' => '02', 'installment_bank_display_name' => 'Krungsri', 'installment_term' => 10]), 'ผ่อนชำระ · Krungsri · 10 เดือน');
check('paid with: installment without a bank', $paidWith(['payment_method' => 'INSTALLMENT', 'installment_term' => 6]), 'ผ่อนชำระ · 6 เดือน');
check('paid with: installment with nothing else', $paidWith(['payment_method' => 'INSTALLMENT']), 'ผ่อนชำระ');
check('paid with: display name', $paidWith(['payment_method' => 'PROMPTPAY', 'payment_method_display_name' => 'PromptPay']), 'PromptPay');
check('paid with: empty display name falls back to the code', $paidWith(['payment_method' => 'CARD', 'payment_method_display_name' => '']), 'CARD');
check('paid with: nothing reported', $paidWith([]), null);

check('paid payment id: the paying attempt', OrderSync::paidPaymentId([
    OrderSync::PAID_ATTEMPT => 'm2-shop-a1b2_order_1_2',
    OrderSync::ATTEMPTS => [
        ['external_id' => 'm2-shop-a1b2_order_1_1', 'payment_id' => 'pay_1'],
        ['external_id' => 'm2-shop-a1b2_order_1_2', 'payment_id' => 'pay_2'],
    ],
]), 'pay_2');
check('paid payment id: unpaid order', OrderSync::paidPaymentId([OrderSync::ATTEMPTS => [['external_id' => 'x', 'payment_id' => 'pay_1']]]), null);
check('paid payment id: order from before attempts', OrderSync::paidPaymentId(['method_title' => 'Reservepay']), null);

check('dashboard payment url', Config::dashboardPaymentUrl('https://dashboard.reservepay.com', '123456', 'pay_abc'), 'https://dashboard.reservepay.com/merchants/live/123456/transactions/payments/pay_abc');
check('dashboard payment url encodes ids', Config::dashboardPaymentUrl('https://dashboard.example.com', 'a/b', 'pay 1'), 'https://dashboard.example.com/merchants/live/a%2Fb/transactions/payments/pay%201');
foreach ([
    'https://dashboard.example.com' => true,
    'http://localhost:5174' => true,
    'http://dashboard.localhost' => true,
    'http://host.docker.internal:3000' => true,
    'http://dashboard.example.com' => false,
    'javascript:alert(1)' => false,
    'dashboard.example.com' => false,
    '' => false,
    'http:///nohost' => false,
] as $url => $safe) {
    check("override allowed: $url", Config::isSafeOverride($url), $safe);
}

check('find-payment expires_at is read', FoundPayment::fromResponse(['payment_id' => 'pay_1', 'status' => 'PENDING', 'amount' => 1, 'currency' => 'THB', 'expires_at' => 1234])->expiresAt, 1234);
check('find-payment without expires_at', FoundPayment::fromResponse(['payment_id' => 'pay_1', 'status' => 'PENDING', 'amount' => 1, 'currency' => 'THB'])->expiresAt, null);

$first = ['external_id' => 'm2-shop-a1b2_order_1_1', 'payment_id' => 'pay_1', 'session_id' => 'pse_1', 'created_at' => 1];
$second = ['external_id' => 'm2-shop-a1b2_order_1_2', 'payment_id' => 'pay_2', 'session_id' => 'pse_2', 'created_at' => 2];
$unstarted = ['external_id' => 'm2-shop-a1b2_order_1_3', 'payment_id' => null, 'session_id' => 'pse_3', 'created_at' => 3];
check('no attempts, nothing to resume', OrderSync::resumeCandidate([]), null);
check('the latest attempt is the candidate', OrderSync::resumeCandidate([$first, $second]), $second);
check('a latest attempt Reservepay never took is not resumed', OrderSync::resumeCandidate([$first, $second, $unstarted]), null);
check('an attempt without a session is not resumed', OrderSync::resumeCandidate([['payment_id' => 'pay_1']]), null);

check('page start: open payment reopens its session', OrderSync::pageStart($second, $payment('PENDING', 1001), 1000), ['paymentSessionId' => 'pse_2', 'paid' => false]);
check('page start: paid payment confirms instead of reopening', OrderSync::pageStart($second, $payment('SUCCESSFUL', 900), 1000), ['paymentSessionId' => null, 'paid' => true]);
check('page start: expired payment starts a new session', OrderSync::pageStart($second, $payment('PENDING', 1000), 1000), ['paymentSessionId' => null, 'paid' => false]);
check('page start: failed payment starts a new session', OrderSync::pageStart($second, $payment('FAILED'), 1000), ['paymentSessionId' => null, 'paid' => false]);
check('page start: no answer from Reservepay starts a new session', OrderSync::pageStart($second, null, 1000), ['paymentSessionId' => null, 'paid' => false]);
check('page start: no attempt starts a new session', OrderSync::pageStart(null, null, 1000), ['paymentSessionId' => null, 'paid' => false]);

check('no attempts, the shopper may cancel', OrderSync::shopperMayCancel('unknown', false), true);
check('every attempt failed, the shopper may cancel', OrderSync::shopperMayCancel('failed', true), true);
check('an open payment does not stop the shopper cancelling', OrderSync::shopperMayCancel('open', true), true);
check('an unknown answer keeps the order', OrderSync::shopperMayCancel('unknown', true), false);
check('a paid order is never cancelled', OrderSync::shopperMayCancel('paid', true), false);

done();
