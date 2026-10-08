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

    // [outcome, captured]. The outcome is what the payment means for an unpaid order. Captured means money moved, so
    // a payment on an order that cannot take it gets flagged. Refunded and disputed money was captured but must never
    // pay an unpaid order.
    public const STATUSES = [
        'SUCCESSFUL' => [self::PAID, true],
        'PARTIALLY_REFUNDED' => [self::UNKNOWN, true],
        'REFUNDED' => [self::UNKNOWN, true],
        'DISPUTED' => [self::UNKNOWN, true],
        'PENDING' => [self::OPEN, false],
        'AUTHORIZED' => [self::OPEN, false],
        // A late bank confirmation can still turn FAILED or EXPIRED into SUCCESSFUL, so failed is never final.
        'FAILED' => [self::FAILED, false],
        'EXPIRED' => [self::FAILED, false],
        'REVERSED' => [self::FAILED, false],
        'VOIDED' => [self::FAILED, false],
    ];

    // Highest first. The order shows failed only when every attempt failed.
    private const PRECEDENCE = [self::PAID, self::OPEN, self::UNKNOWN, self::FAILED];

    public static function outcome(?string $status): string
    {
        return self::STATUSES[strtoupper((string) $status)][0] ?? self::UNKNOWN;
    }

    public static function captured(?string $status): bool
    {
        return self::STATUSES[strtoupper((string) $status)][1] ?? false;
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
