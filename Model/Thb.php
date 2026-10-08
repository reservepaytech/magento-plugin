<?php

namespace Reservepay\Payment\Model;

/**
 * Reservepay only takes Thai baht for now, and its API counts in satang. Observer\OfferedGroupsOnly hides the payment
 * methods from any quote in another currency.
 */
class Thb
{
    public const CODE = 'THB';

    public static function satang(float|string $baht): int
    {
        return (int) round((float) $baht * 100);
    }
}
