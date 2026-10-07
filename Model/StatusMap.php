<?php

namespace Reservepay\Payment\Model;

/**
 * The single mapping from a Reservepay payment status to what it means for the order.
 */
class StatusMap
{
    public const PAID = 'paid';
    public const OPEN = 'open';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    private const OUTCOMES = [
        'SUCCESSFUL' => self::PAID,
        'PARTIALLY_REFUNDED' => self::PAID,
        'REFUNDED' => self::PAID,
        'DISPUTED' => self::PAID,
        'PENDING' => self::OPEN,
        'AUTHORIZED' => self::OPEN,
        // A late bank confirmation can still turn FAILED or EXPIRED into SUCCESSFUL, so failed is never final.
        'FAILED' => self::FAILED,
        'EXPIRED' => self::FAILED,
        'REVERSED' => self::FAILED,
        'VOIDED' => self::FAILED,
    ];

    // Highest first. The order shows failed only when every attempt failed.
    private const PRECEDENCE = [self::PAID, self::OPEN, self::UNKNOWN, self::FAILED];

    public static function outcome(?string $status): string
    {
        return self::OUTCOMES[strtoupper((string) $status)] ?? self::UNKNOWN;
    }

    /**
     * @param string[] $outcomes
     */
    public static function aggregate(array $outcomes): string
    {
        foreach (self::PRECEDENCE as $outcome) {
            if (in_array($outcome, $outcomes, true)) {
                return $outcome;
            }
        }
        return self::UNKNOWN;
    }
}
