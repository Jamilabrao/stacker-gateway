<?php

namespace App\Support;

/**
 * Normalização e cálculo de taxas percentuais sem drift de float (ex.: 0,99%).
 */
final class PercentDecimal
{
    private const SCALE = 8;

    /**
     * Normaliza percentual para string com até 4 casas decimais (0–100).
     */
    public static function normalize(mixed $input): string
    {
        if ($input === null || $input === '') {
            return '0';
        }

        if (is_string($input)) {
            $s = trim(str_replace(',', '.', $input));
            if ($s === '' || ! is_numeric($s)) {
                return '0';
            }
            $input = $s;
        }

        if (! is_int($input) && ! is_float($input) && ! is_string($input)) {
            return '0';
        }

        $scaled = bcmul((string) $input, '10000', 0);
        $asInt = (int) $scaled;
        $whole = intdiv($asInt, 10000);
        $frac = $asInt % 10000;

        if ($frac === 0) {
            return (string) $whole;
        }

        $fracStr = str_pad((string) $frac, 4, '0', STR_PAD_LEFT);
        $fracStr = rtrim($fracStr, '0');

        return $whole.'.'.$fracStr;
    }

    public static function toFloat(string $normalized): float
    {
        return (float) self::normalize($normalized);
    }

    /**
     * Taxa e líquido a partir do bruto (R$), percentual e fixo.
     *
     * @return array{fee: float, net: float}
     */
    public static function feeFromGross(float $grossBrl, string $percent, float $fixedBrl): array
    {
        $gross = self::normalizeMoney($grossBrl);
        $pct = self::normalize($percent);
        $fixed = self::normalizeMoney($fixedBrl);

        if (bccomp($gross, '0', self::SCALE) <= 0) {
            return ['fee' => 0.0, 'net' => 0.0];
        }

        $fee = bcadd(
            bcdiv(bcmul($gross, $pct, self::SCALE), '100', self::SCALE),
            $fixed,
            self::SCALE
        );

        if (bccomp($fee, $gross, self::SCALE) > 0) {
            $fee = $gross;
        }

        $feeRounded = self::roundMoneyHalfUp($fee);
        $netRounded = self::roundMoneyHalfUp(bcsub($gross, $feeRounded, self::SCALE));

        return [
            'fee' => (float) $feeRounded,
            'net' => (float) $netRounded,
        ];
    }

    private static function normalizeMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private static function roundMoneyHalfUp(string $amount): string
    {
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return '0.00';
        }

        $negative = bccomp($amount, '0', self::SCALE) < 0;
        if ($negative) {
            $amount = ltrim($amount, '-');
        }

        $centsRounded = bcadd(bcmul($amount, '100', 4), '0.5', 0);
        $result = bcdiv($centsRounded, '100', 2);

        return $negative ? '-'.$result : $result;
    }
}
