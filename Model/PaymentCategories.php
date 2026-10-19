<?php

namespace Reservepay\Payment\Model;

/**
 * The Reservepay payment categories, one checkout payment method each. Which of them checkout shows is decided in the
 * browser by the Reservepay SDK from the installation settings (view/base/web/js/payment-category-options.js).
 * Plain PHP, so the test runs without Magento.
 */
class PaymentCategories
{
    /** Category => payment method code, in the SDK chooser's order. CARD leads: the checkout JS falls back to the first. */
    public const CODES = [
        'CARD' => 'reservepay_card',
        'INSTALLMENT' => 'reservepay_installment',
        'SCAN_TO_PAY' => 'reservepay_scan_to_pay',
        'MOBILE_BANKING' => 'reservepay_mobile_banking',
        'EWALLET_APP' => 'reservepay_ewallet_app',
    ];
    /** Holds the settings every category shares. Never offered at checkout, so no order carries it. */
    public const SETTINGS_CODE = 'reservepay_payment';

    /**
     * @return string[] every payment method code a Reservepay order can carry
     */
    public static function methodCodes(): array
    {
        return array_values(self::CODES);
    }

    public static function isReservepay(?string $methodCode): bool
    {
        return in_array($methodCode, self::methodCodes(), true);
    }

    public static function categoryOf(?string $methodCode): ?string
    {
        $category = array_search($methodCode, self::CODES, true);
        return $category === false ? null : $category;
    }
}
