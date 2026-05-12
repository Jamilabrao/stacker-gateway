<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;

class CheckoutCustomPriceByCurrency
{
    /**
     * @param  array<int, array{code?: string, rate_to_brl?: float|int|string}>  $tenantCurrencies
     */
    public static function amountBrlFromCustomIfApplicable(
        Product $product,
        ?ProductOffer $offer,
        ?SubscriptionPlan $plan,
        float $amountBrl,
        string $displayCurrency,
        array $tenantCurrencies
    ): float {
        $cfg = $product->checkout_config['custom_prices_by_currency'] ?? [];
        if (! is_array($cfg) || empty($cfg['enabled'])) {
            return $amountBrl;
        }
        if ($offer !== null || $plan !== null) {
            return $amountBrl;
        }
        $code = strtoupper(trim($displayCurrency));
        if ($code === '' || $code === 'BRL') {
            return $amountBrl;
        }
        $amounts = $cfg['amounts'] ?? [];
        if (! is_array($amounts) || ! isset($amounts[$code])) {
            return $amountBrl;
        }
        $custom = (float) $amounts[$code];
        if ($custom <= 0) {
            return $amountBrl;
        }
        $rate = self::rateToBrlForCode($tenantCurrencies, $code);
        if ($rate <= 0) {
            return $amountBrl;
        }

        return round($custom / $rate, 2);
    }

    /**
     * @param  array<int, array{code?: string, rate_to_brl?: float|int|string}>  $tenantCurrencies
     */
    public static function rateToBrlForCode(array $tenantCurrencies, string $code): float
    {
        foreach ($tenantCurrencies as $row) {
            if (! is_array($row)) {
                continue;
            }
            $c = isset($row['code']) ? strtoupper(trim((string) $row['code'])) : '';
            if ($c === $code) {
                return max(0.0, (float) ($row['rate_to_brl'] ?? 0));
            }
        }

        return 0.0;
    }

    /**
     * @param  array<int, array{code?: string}>  $tenantCurrencies
     * @return list<string>
     */
    public static function currencyCodesFromTenantSettings(array $tenantCurrencies): array
    {
        $out = [];
        foreach ($tenantCurrencies as $row) {
            if (! is_array($row)) {
                continue;
            }
            $c = isset($row['code']) ? strtoupper(trim((string) $row['code'])) : '';
            if ($c !== '') {
                $out[] = $c;
            }
        }
        if (! in_array('BRL', $out, true)) {
            array_unshift($out, 'BRL');
        }

        return array_values(array_unique($out));
    }
}
