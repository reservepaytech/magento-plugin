<?php
// Run with: php tests/billing-prefill-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/BillingPrefill.php';

use Reservepay\Payment\Model\BillingPrefill;

$mobiles = [
    ['+66 81 234 5678', 'TH', '0812345678'],
    ['66812345678', 'TH', '0812345678'],
    ['081-234-5678', 'TH', '0812345678'],
    ['+66 (0)81 234 5678', 'TH', '0812345678'],
    ['0066812345678', 'TH', '0812345678'],
    ['+66 2 123 4567', 'TH', '021234567'],
    ['+66812345678', 'th', '0812345678'],
    ['66', 'TH', '66'],
    ['+66 81 234 5678', 'SG', '66812345678'],
    ['+65 6123-4567', 'SG', '6561234567'],
    ['', 'TH', ''],
    [null, null, ''],
];
foreach ($mobiles as [$phone, $country, $expected]) {
    $label = 'mobile ' . var_export($phone, true) . ' ' . var_export($country, true);
    check($label, BillingPrefill::mobile($phone, $country), $expected);
}

check('full Thai billing', BillingPrefill::formOptions([
    'street' => [' 123 Example Road ', 'Floor 4', 'Unit 5'],
    'city' => 'Pathum Wan',
    'region' => 'Bangkok',
    'postcode' => '10330',
    'country_id' => 'TH',
    'email' => 'shopper@example.com',
    'telephone' => '+66 81 234 5678',
]), [
    'customerEmail' => 'shopper@example.com',
    'customerMobile' => '0812345678',
    'billingAddress' => [
        'streetAddress1' => '123 Example Road',
        'streetAddress2' => 'Floor 4, Unit 5',
        'city' => 'Pathum Wan',
        'state' => 'Bangkok',
        'postalCode' => '10330',
        'country' => 'TH',
    ],
]);
check('blank street lines dropped', BillingPrefill::formOptions(['street' => ['', '1 Example Road', ' ', 'Floor 2']]), [
    'billingAddress' => ['streetAddress1' => '1 Example Road', 'streetAddress2' => 'Floor 2'],
]);
check('nulls and blanks left out', BillingPrefill::formOptions([
    'street' => [],
    'city' => null,
    'region' => '  ',
    'postcode' => null,
    'country_id' => 'TH',
    'email' => null,
    'telephone' => null,
]), ['billingAddress' => ['country' => 'TH']]);
check(
    'no billing address',
    BillingPrefill::formOptions(['email' => 'shopper@example.com', 'street' => null]),
    ['customerEmail' => 'shopper@example.com']
);
check('nothing to prefill', BillingPrefill::formOptions([]), []);
check('unknown keys ignored', BillingPrefill::formOptions(['company' => 'Example Co']), []);

done();
