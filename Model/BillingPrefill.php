<?php

namespace Reservepay\Payment\Model;

/**
 * The order's billing details as SDK form options, so the shopper does not type them twice. Plain PHP, no Magento.
 */
class BillingPrefill
{
    /**
     * Empty values are left out.
     *
     * $billing keys: street (Magento's lines), city, region, postcode, country_id, email, telephone. The SDK has two
     * street fields, so every line after the first goes into the second.
     */
    public static function formOptions(array $billing): array
    {
        $value = static fn (string $key): string => trim((string) ($billing[$key] ?? ''));
        $street = array_values(array_filter(
            array_map(static fn ($line): string => trim((string) $line), (array) ($billing['street'] ?? [])),
            static fn (string $line): bool => $line !== ''
        ));
        $address = array_filter([
            'streetAddress1' => $street[0] ?? '',
            'streetAddress2' => implode(', ', array_slice($street, 1)),
            'city' => $value('city'),
            'state' => $value('region'),
            'postalCode' => $value('postcode'),
            'country' => $value('country_id'),
        ], static fn (string $field): bool => $field !== '');
        $options = array_filter([
            'customerEmail' => $value('email'),
            'customerMobile' => self::mobile($value('telephone'), $value('country_id')),
        ], static fn (string $field): bool => $field !== '');
        if ($address) {
            $options['billingAddress'] = $address;
        }
        return $options;
    }

    /**
     * The SDK's mobile field takes a national number, digits only. A Thai number in international form, such as
     * +66 81 234 5678 or +66 (0)81 234 5678, becomes 0812345678.
     */
    public static function mobile(?string $phone, ?string $country): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);
        if (strtoupper((string) $country) === 'TH' && preg_match('/^(?:00)?660?(\d{8,9})$/', $digits, $match)) {
            return '0' . $match[1];
        }
        return $digits;
    }
}
