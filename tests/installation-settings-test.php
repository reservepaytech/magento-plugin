<?php
// Run with: php tests/installation-settings-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/InstallationSettings.php';

use Reservepay\Payment\Model\InstallationSettings;

check('method names in order, each once', InstallationSettings::methodNames(['payment_methods' => [
    ['name' => 'CARD', 'category' => 'CARD'],
    ['name' => 'PROMPTPAY', 'category' => 'SCAN_TO_PAY'],
    ['name' => 'CARD', 'category' => 'CARD'],
    ['name' => null, 'category' => 'MOBILE_BANKING'],
    ['name' => '', 'category' => 'EWALLET_APP'],
    ['category' => 'CARD'],
]]), ['CARD', 'PROMPTPAY']);
check('no payment methods', InstallationSettings::methodNames(['payment_methods' => []]), []);
check('settings without payment methods', InstallationSettings::methodNames([]), []);

done();
