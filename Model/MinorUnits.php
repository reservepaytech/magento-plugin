<?php

namespace Reservepay\Payment\Model;

/**
 * Converts a major-unit amount to the integer minor units Reservepay expects, using the ISO 4217 exponent.
 */
class MinorUnits
{
    private const EXPONENTS = [
        'JPY' => 0,
        'KRW' => 0,
        'BHD' => 3,
        'JOD' => 3,
        'KWD' => 3,
        'OMR' => 3,
    ];

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    public static function fromMajor(float|string $amount, string $currency): int
    {
        return (int) round((float) $amount * (10 ** self::exponent($currency)));
    }
}
