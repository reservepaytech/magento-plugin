<?php

namespace Reservepay\Payment\Model\Api;

use Reservepay\Payment\Model\StatusMap;

/**
 * The fields of a find-payment response that the order sync relies on.
 */
class FoundPayment
{
    // find-payment's card_network values, as shoppers know them.
    private const CARD_NETWORKS = [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'jcb' => 'JCB',
        'amex' => 'American Express',
        'american_express' => 'American Express',
        'unionpay' => 'UnionPay',
    ];

    public function __construct(
        public readonly string $paymentId,
        public readonly ?string $externalId,
        public readonly ?string $paymentSessionId,
        public readonly string $status,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paymentMethodDisplayName = null,
        public readonly ?int $expiresAt = null,
        public readonly ?string $cardNetwork = null,
        public readonly ?string $cardLastDigits = null,
        public readonly ?string $installmentBank = null,
        public readonly ?int $installmentTerm = null
    ) {
    }

    /**
     * Whether the payment form can reopen this payment's session: open and not past its expiry. A payment past its
     * expires_at can no longer be paid, even while its status is still open.
     */
    public function resumable(int $now): bool
    {
        return StatusMap::outcome($this->status) === StatusMap::OPEN && ($this->expiresAt === null || $this->expiresAt > $now);
    }

    /**
     * What the shopper actually paid with, for example "Visa •• 02", "Installment · Krungsri · 10 months" or
     * "PromptPay", or null when Reservepay said nothing about the method.
     *
     * @param callable(string, mixed...): string $phrase translates "Installment" and "%1 months", like __()
     */
    public function paidWith(callable $phrase): ?string
    {
        if (strtoupper((string) $this->paymentMethod) === 'INSTALLMENT' || $this->installmentTerm !== null) {
            return self::withDetail((string) $phrase('Installment'), [
                $this->installmentBank,
                $this->installmentTerm !== null ? (string) $phrase('%1 months', $this->installmentTerm) : null,
            ]);
        }
        $name = $this->paymentMethodDisplayName ?? $this->paymentMethod;
        if ($this->cardNetwork !== null) {
            $card = trim((self::CARD_NETWORKS[strtolower($this->cardNetwork)] ?? '')
                . ($this->cardLastDigits !== null ? ' •• ' . $this->cardLastDigits : ''));
            return self::withDetail($name ?? (string) $phrase('Card'), [$card]);
        }
        return $name;
    }

    /**
     * "Card (Visa •• 02)", "Installment (Krungsri, 10 months)", or the method alone when there is no detail.
     *
     * @param array<?string> $details
     */
    private static function withDetail(string $method, array $details): string
    {
        $details = array_filter($details, fn (?string $detail) => $detail !== null && $detail !== '');
        return $details ? $method . ' (' . implode(', ', $details) . ')' : $method;
    }

    /**
     * Reservepay does not keep external ids unique, and a store clone can reuse ours, so the external id must match
     * as well as the stored payment id, or the session when no payment id is stored yet.
     *
     * @param array{external_id: string, payment_id: ?string, session_id: string} $attempt
     */
    public function belongsTo(array $attempt): bool
    {
        if ($this->externalId !== $attempt['external_id']) {
            return false;
        }
        return $attempt['payment_id'] !== null
            ? $this->paymentId === $attempt['payment_id']
            : $this->paymentSessionId === $attempt['session_id'];
    }

    public static function fromResponse(mixed $body): self
    {
        if (!is_array($body)
            || !is_string($body['payment_id'] ?? null)
            || !is_string($body['status'] ?? null)
            || !is_int($body['amount'] ?? null)
            || !is_string($body['currency'] ?? null)
        ) {
            throw new ApiException(ApiException::BAD_RESPONSE, 'find-payment returned an unexpected shape');
        }
        return new self(
            $body['payment_id'],
            self::optionalString($body['external_id'] ?? null),
            self::optionalString($body['payment_session_id'] ?? null),
            $body['status'],
            $body['amount'],
            $body['currency'],
            self::optionalString($body['payment_method'] ?? null),
            self::optionalString($body['payment_method_display_name'] ?? null),
            is_int($body['expires_at'] ?? null) ? $body['expires_at'] : null,
            self::optionalString($body['card_network'] ?? null),
            self::optionalString($body['card_last_digits'] ?? null),
            self::optionalString($body['installment_bank_display_name'] ?? null),
            is_int($body['installment_term'] ?? null) ? $body['installment_term'] : null
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
