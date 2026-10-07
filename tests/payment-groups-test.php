<?php
// Run with: php tests/payment-groups-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/PaymentGroups.php';

use Reservepay\Payment\Model\PaymentGroups as G;

$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/payment-groups.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixture['cases'] as $case) {
    check('fixture: ' . $case['name'], G::available($case['settings']), $case['expected']);
}

$settings = [
    'interest_bearer' => 'merchant',
    'payment_methods' => [
        ['name' => 'CARD', 'category' => 'card'],
        ['name' => 'INSTALLMENT', 'category' => 'card', 'banks' => [['name' => 'KTC'], ['name' => 'BAY']]],
        ['name' => 'PROMPTPAY', 'category' => 'scan_to_pay'],
        ['name' => 'KBANK', 'category' => 'mobile_banking'],
        ['name' => 'SCB', 'category' => 'MOBILE_BANKING'],
        ['category' => 'ewallet_app'],
    ],
];
check('logo symbols per group', G::logoSymbols($settings, ['VISA', 'MASTERCARD']), [
    'CARD' => ['VISA', 'MASTERCARD'],
    'INSTALLMENT' => ['KTC', 'BAY'],
    'SCAN_TO_PAY' => ['PROMPTPAY'],
    'MOBILE_BANKING' => ['KBANK', 'SCB'],
    'EWALLET_APP' => [],
]);
check('logo symbols, no settings', G::logoSymbols(null, [])['SCAN_TO_PAY'], []);

check('symbol trimmed and uppercased', G::normalizeSymbol(' visa '), 'VISA');
check('AMERICAN-EXPRESS alias', G::normalizeSymbol('american-express'), 'AMEX');
check('AMERICAN_EXPRESS alias', G::normalizeSymbol('AMERICAN_EXPRESS'), 'AMEX');

$logos = [];
foreach (['VISA', 'MASTERCARD', 'AMEX', 'JCB', 'UNIONPAY'] as $symbol) {
    $logos[$symbol] = ['src' => "https://sdk.example/$symbol.svg", 'alt' => $symbol];
}
$picked = G::pickLogos(['VISA', 'MASTERCARD', 'american_express', 'JCB', 'UNIONPAY'], $logos);
check('five logos -> first 3', array_column($picked['logos'], 'alt'), ['VISA', 'MASTERCARD', 'AMEX']);
check('five logos -> +2', $picked['more'], 2);
$picked = G::pickLogos(['NOT_IN_MANIFEST', 'VISA', 'ALSO_MISSING'], $logos);
check('symbols without a logo are skipped', array_column($picked['logos'], 'alt'), ['VISA']);
check('symbols without a logo still count in +N', $picked['more'], 2);
check('no drawable logo -> no +N either', G::pickLogos(['A', 'B'], $logos), ['logos' => [], 'more' => 0]);
check('empty manifest -> no logos', G::pickLogos(['VISA'], []), ['logos' => [], 'more' => 0]);

check('method code per group', G::CODES['SCAN_TO_PAY'], 'reservepay_scan_to_pay');
check('group of a method code', G::groupOf('reservepay_ewallet_app'), 'EWALLET_APP');
check('settings code has no group', G::groupOf('reservepay_payment'), null);
check('group method is Reservepay', G::isReservepay('reservepay_installment'), true);
check('settings code is not an order method', G::isReservepay('reservepay_payment'), false);
check('another method is not Reservepay', G::isReservepay('checkmo'), false);

done();
