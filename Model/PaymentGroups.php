<?php

namespace Reservepay\Payment\Model;

/**
 * The Reservepay payment groups, one checkout payment method each, and the SDK chooser's rules for which groups an
 * installation offers and which logos each shows. tests/fixtures/payment-groups.json, built from the SDK's own test
 * table, keeps these rules equal to getAvailablePaymentGroups in browser-sdk. Plain PHP, so the test runs without
 * Magento.
 */
class PaymentGroups
{
    /** Group => payment method code, in the SDK chooser's order. */
    public const CODES = [
        'CARD' => 'reservepay_card',
        'INSTALLMENT' => 'reservepay_installment',
        'SCAN_TO_PAY' => 'reservepay_scan_to_pay',
        'MOBILE_BANKING' => 'reservepay_mobile_banking',
        'EWALLET_APP' => 'reservepay_ewallet_app',
    ];
    /** Holds the settings every group shares. Never offered at checkout, so no order carries it. */
    public const SETTINGS_CODE = 'reservepay_payment';
    public const DEFAULT_GROUP = 'CARD';
    public const MAX_LOGOS = 3;

    private const SYMBOL_ALIASES = ['AMERICAN-EXPRESS' => 'AMEX', 'AMERICAN_EXPRESS' => 'AMEX'];

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

    public static function groupOf(?string $methodCode): ?string
    {
        $group = array_search($methodCode, self::CODES, true);
        return $group === false ? null : $group;
    }

    /**
     * Port of the SDK's getAvailablePaymentGroups: the groups an installation offers, in chooser order.
     *
     * @return string[]
     */
    public static function available(?array $settings): array
    {
        $methods = self::methods($settings);
        $names = array_map(fn ($method) => self::upper($method['name'] ?? null), $methods);
        $groups = [];
        foreach (array_keys(self::CODES) as $group) {
            if ($group === 'INSTALLMENT') {
                if (!empty($settings['interest_bearer']) && in_array('INSTALLMENT', $names, true)) {
                    $groups[] = $group;
                }
                continue;
            }
            foreach ($methods as $i => $method) {
                // INSTALLMENT shares category "card", so CARD skips it or an installment-only merchant would get a CARD row.
                if (self::upper($method['category'] ?? null) === $group && !($group === 'CARD' && $names[$i] === 'INSTALLMENT')) {
                    $groups[] = $group;
                    break;
                }
            }
        }
        return $groups;
    }

    /**
     * Port of the SDK chooser's logo symbols per group. CARD has no per-merchant list, so it shows the manifest's card
     * brands, like the SDK.
     *
     * @param string[] $cardSymbols
     * @return array<string, string[]>
     */
    public static function logoSymbols(?array $settings, array $cardSymbols): array
    {
        $symbols = array_fill_keys(array_keys(self::CODES), []);
        $symbols['CARD'] = $cardSymbols;
        foreach (self::methods($settings) as $method) {
            $name = $method['name'] ?? null;
            if (self::upper($name) === 'INSTALLMENT') {
                $banks = is_array($method['banks'] ?? null) ? $method['banks'] : [];
                $symbols['INSTALLMENT'] = array_values(array_filter(array_map(
                    fn ($bank) => is_array($bank) && is_string($bank['name'] ?? null) ? $bank['name'] : null,
                    $banks
                )));
                continue;
            }
            $category = self::upper($method['category'] ?? null);
            if ($category !== 'CARD' && isset($symbols[$category]) && is_string($name) && $name !== '') {
                $symbols[$category][] = $name;
            }
        }
        return $symbols;
    }

    public static function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        return self::SYMBOL_ALIASES[$symbol] ?? $symbol;
    }

    /**
     * Same as the SDK chooser: the first MAX_LOGOS symbols that have a logo, and +N for every other configured symbol,
     * drawn or not. No logo at all means no badge either, since a bare "+4" says nothing.
     *
     * @param string[] $symbols
     * @param array<string, array{src: string, alt: string}> $logos by normalized symbol
     * @return array{logos: list<array{src: string, alt: string}>, more: int}
     */
    public static function pickLogos(array $symbols, array $logos): array
    {
        $picked = [];
        foreach ($symbols as $symbol) {
            $logo = $logos[self::normalizeSymbol($symbol)] ?? null;
            if ($logo !== null && count($picked) < self::MAX_LOGOS) {
                $picked[] = $logo;
            }
        }
        return ['logos' => $picked, 'more' => $picked ? count($symbols) - count($picked) : 0];
    }

    private static function methods(?array $settings): array
    {
        $methods = $settings['payment_methods'] ?? null;
        return is_array($methods) ? array_values(array_filter($methods, 'is_array')) : [];
    }

    private static function upper(mixed $value): string
    {
        return is_string($value) ? strtoupper($value) : '';
    }
}
