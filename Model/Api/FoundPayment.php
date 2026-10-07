<?php

namespace Reservepay\Payment\Model\Api;

/**
 * The fields of a find-payment response that the order sync relies on.
 */
class FoundPayment
{
    public function __construct(
        public readonly string $paymentId,
        public readonly ?string $externalId,
        public readonly ?string $paymentSessionId,
        public readonly string $status,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paymentMethodDisplayName = null
    ) {
    }

    /**
     * The method the shopper actually paid with, for example "Card (CARD)", or null when Reservepay did not say.
     */
    public function paidWith(): ?string
    {
        if ($this->paymentMethodDisplayName !== null && $this->paymentMethod !== null) {
            return $this->paymentMethodDisplayName . ' (' . $this->paymentMethod . ')';
        }
        return $this->paymentMethodDisplayName ?? $this->paymentMethod;
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
            self::optionalString($body['payment_method_display_name'] ?? null)
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
