<?php
// Run with: php tests/webhook-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/ExternalId.php';
require __DIR__ . '/../Model/Webhook.php';

use Reservepay\Payment\Model\ExternalId;
use Reservepay\Payment\Model\Webhook;

// 32 bytes of "k", Base64 encoded as the dashboard shows it. The signature was computed with openssl.
$key = 'a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';
$body = '{"event_id":"evt_test","event":"payment_completed","payment_id":"pay_test","external_id":"m2-example-a1b2_order_000000047_1","status":"SUCCESSFUL","delivered_at":1767225600}';
$hex = 'fbff34e336d9e9c1b0dad49fb205490f26b1efb417732966634a9ebddd207398';

check('valid signature', Webhook::signatureValid($body, "hmac_sha256=$hex", $key), true);
check('valid signature, uppercase hex', Webhook::signatureValid($body, 'hmac_sha256=' . strtoupper($hex), $key), true);
check('wrong key', Webhook::signatureValid($body, "hmac_sha256=$hex", base64_encode(str_repeat('j', 32))), false);
check('key not Base64-decoded is a wrong key', Webhook::signatureValid($body, 'hmac_sha256=' . hash_hmac('sha256', $body, $key), $key), false);
check('tampered body', Webhook::signatureValid(str_replace('SUCCESSFUL', 'FAILED', $body), "hmac_sha256=$hex", $key), false);
check('re-encoded body', Webhook::signatureValid(json_encode(json_decode($body), JSON_PRETTY_PRINT), "hmac_sha256=$hex", $key), false);
check('missing header', Webhook::signatureValid($body, '', $key), false);
check('legacy sha256 scheme', Webhook::signatureValid($body, "sha256=$hex", $key), false);
check('ed25519 scheme', Webhook::signatureValid($body, "ed25519=$hex", $key), false);
check('scheme only', Webhook::signatureValid($body, 'hmac_sha256=', $key), false);
check('bare hex', Webhook::signatureValid($body, $hex, $key), false);
check('truncated hex', Webhook::signatureValid($body, 'hmac_sha256=' . substr($hex, 0, 63), $key), false);
check('trailing junk', Webhook::signatureValid($body, "hmac_sha256=$hex,v2", $key), false);
check('non-Base64 key', Webhook::signatureValid($body, "hmac_sha256=$hex", 'not base64!'), false);
check('empty key', Webhook::signatureValid($body, 'hmac_sha256=' . hash_hmac('sha256', $body, ''), ''), false);

check('order ref from an attempt id', ExternalId::orderRef('m2-demo-local-3f9a_order_000000047_1'), '000000047');
check('order ref, tenth attempt', ExternalId::orderRef('m2-demo-local-3f9a_order_000000047_10'), '000000047');
check('order ref, entity id fallback', ExternalId::orderRef('m2-averyveryv-ffff_order_4711_1'), '4711');
check('order ref, no host', ExternalId::orderRef('m2-a1b2_order_100000001_2'), '100000001');
check('order ref, custom increment id with underscores', ExternalId::orderRef('m2-shop-a1b2_order_A_1_7_3'), 'A_1_7');
check('round trip with format()', ExternalId::orderRef(ExternalId::format('m2-demo-local-a1b2', '000000035', 35, 2)), '000000035');
$junk = [
    '',
    'm2-demo-local-3f9a_order_000000047',
    'm2-demo-local-3f9a_order__1',
    'm2-demo-local-3f9a_order_000000047_0',
    'm2-demo-local-3f9a_order_000000047_x',
    'M2-DEMO_order_000000047_1',
    'wc-demo-a1b2_order_47_1',
    'wc1-47-a1b2c3',
    'pay_2abc',
    '000000047',
    ' m2-demo-local-3f9a_order_000000047_1',
];
foreach ($junk as $externalId) {
    check("no order ref in \"$externalId\"", ExternalId::orderRef($externalId), null);
}

check('handles the five payment events', Webhook::PAYMENT_EVENTS, ['payment_authorized', 'payment_completed', 'payment_expired', 'payment_voided', 'payment_reversed']);

done();
