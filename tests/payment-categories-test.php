<?php
// Run with: php tests/payment-categories-test.php
require __DIR__ . '/check.php';
require __DIR__ . '/../Model/PaymentCategories.php';

use Reservepay\Payment\Model\PaymentCategories as C;

check('method codes in chooser order', C::methodCodes(), [
    'reservepay_card',
    'reservepay_installment',
    'reservepay_scan_to_pay',
    'reservepay_mobile_banking',
    'reservepay_ewallet_app',
]);
check('category of a method code', C::categoryOf('reservepay_ewallet_app'), 'EWALLET_APP');
check('settings code has no category', C::categoryOf('reservepay_payment'), null);
check('another method has no category', C::categoryOf('checkmo'), null);
check('category method is Reservepay', C::isReservepay('reservepay_installment'), true);
check('settings code is not an order method', C::isReservepay('reservepay_payment'), false);
check('another method is not Reservepay', C::isReservepay('checkmo'), false);

done();
