<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\PercentDecimal;

class EffectiveMerchantFees
{
    /** Origens em que o PIX usa a taxa `api_pix` (REST ou checkout hospedado criado pela API). Cartão/boleto seguem taxas de checkout. */
    public const API_ORDER_SOURCES = ['api', 'api_checkout_pro'];

    /** @var list<string> */
    private const RULE_KEYS = ['pix', 'api_pix', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'];

    /**
     * @return array{
     *     pix: array{percent: float, fixed: float},
     *     api_pix: array{percent: float, fixed: float},
     *     card: array{percent: float, fixed: float},
     *     apple_pay: array{percent: float, fixed: float},
     *     google_pay: array{percent: float, fixed: float},
     *     boleto: array{percent: float, fixed: float},
     *     withdrawal: array{percent: float, fixed: float}
     * }
     */
    public static function platformDefaults(): array
    {
        $raw = Setting::get('merchant_fee_rules', null, null);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $base = [
            'pix' => ['percent' => 0.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 0.0, 'fixed' => 0.0],
            'card' => ['percent' => 0.0, 'fixed' => 0.0],
            'apple_pay' => ['percent' => 0.0, 'fixed' => 0.0],
            'google_pay' => ['percent' => 0.0, 'fixed' => 0.0],
            'boleto' => ['percent' => 0.0, 'fixed' => 0.0],
            'withdrawal' => ['percent' => 0.0, 'fixed' => 0.0],
        ];
        if (! is_array($raw)) {
            return $base;
        }
        foreach (self::RULE_KEYS as $k) {
            if (! isset($raw[$k]) || ! is_array($raw[$k])) {
                continue;
            }
            $base[$k]['percent'] = PercentDecimal::toFloat(PercentDecimal::normalize($raw[$k]['percent'] ?? 0));
            $base[$k]['fixed'] = round((float) ($raw[$k]['fixed'] ?? 0), 2);
        }
        // Primeira configuração / legado: sem bloco `api_pix`, herda PIX checkout.
        if (! isset($raw['api_pix']) || ! is_array($raw['api_pix'])) {
            $base['api_pix'] = $base['pix'];
        }
        // Wallets CajuPay: sem bloco próprio, herdam taxa de cartão checkout.
        if (! isset($raw['apple_pay']) || ! is_array($raw['apple_pay'])) {
            $base['apple_pay'] = $base['card'];
        }
        if (! isset($raw['google_pay']) || ! is_array($raw['google_pay'])) {
            $base['google_pay'] = $base['card'];
        }

        return $base;
    }

    /**
     * Método de taxa alinhado ao checkout (ex.: CajuPay SDK: apple_pay / google_pay em metadata).
     */
    public static function feeMethodForOrder(Order $order): string
    {
        $method = $order->payment_method;
        if ($method === null || $method === '') {
            $meta = $order->metadata ?? [];
            $method = is_array($meta) ? ($meta['checkout_payment_method'] ?? null) : null;
        }
        $feeMethod = (string) ($method ?: 'pix');
        $meta = is_array($order->metadata ?? null) ? $order->metadata : [];
        $cajuWallet = isset($meta['cajupay_wallet']) ? strtolower(trim((string) $meta['cajupay_wallet'])) : '';
        if (($method === 'card' || $feeMethod === 'card') && in_array($cajuWallet, ['apple_pay', 'google_pay'], true)) {
            return $cajuWallet;
        }

        return $feeMethod;
    }

    /**
     * @return array{
     *     pix: array{percent: float, fixed: float},
     *     api_pix: array{percent: float, fixed: float},
     *     card: array{percent: float, fixed: float},
     *     apple_pay: array{percent: float, fixed: float},
     *     google_pay: array{percent: float, fixed: float},
     *     boleto: array{percent: float, fixed: float},
     *     withdrawal: array{percent: float, fixed: float}
     * }
     */
    public static function forTenant(int $tenantId): array
    {
        $defaults = self::platformDefaults();
        // tenant_id em pedidos/carteira é o id do infoprodutor dono; overrides ficam em users.merchant_fees.
        $owner = User::query()
            ->where('id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($owner === null) {
            $owner = User::query()
                ->where('tenant_id', $tenantId)
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->first();
        }
        if ($owner === null || empty($owner->merchant_fees) || ! is_array($owner->merchant_fees)) {
            return $defaults;
        }
        $ov = $owner->merchant_fees;
        foreach (self::RULE_KEYS as $k) {
            if (! isset($ov[$k]) || ! is_array($ov[$k])) {
                continue;
            }
            if (array_key_exists('percent', $ov[$k])) {
                $defaults[$k]['percent'] = PercentDecimal::toFloat(PercentDecimal::normalize($ov[$k]['percent']));
            }
            if (array_key_exists('fixed', $ov[$k])) {
                $defaults[$k]['fixed'] = round((float) $ov[$k]['fixed'], 2);
            }
        }

        return $defaults;
    }

    /**
     * @param  'pix'|'card'|'apple_pay'|'google_pay'|'boleto'|'withdrawal'|'pix_auto'  $method
     * @return array{fee: float, net: float, gross: float, percent: float, fixed: float}
     */
    public static function calculateSaleFee(int $tenantId, string $method, float $gross, ?string $source = null): array
    {
        $map = [
            'pix' => 'pix',
            'card' => 'card',
            'apple_pay' => 'apple_pay',
            'google_pay' => 'google_pay',
            'boleto' => 'boleto',
            'pix_auto' => 'pix',
        ];
        $key = $map[$method] ?? $method;
        if (! in_array($key, ['pix', 'card', 'apple_pay', 'google_pay', 'boleto'], true)) {
            $key = 'pix';
        }
        $rules = self::forTenant($tenantId);
        if ($key === 'pix' && $source !== null && in_array($source, self::API_ORDER_SOURCES, true)) {
            $key = 'api_pix';
        }
        $percent = PercentDecimal::toFloat(PercentDecimal::normalize($rules[$key]['percent'] ?? 0));
        $fixed = round((float) ($rules[$key]['fixed'] ?? 0), 2);
        $amounts = PercentDecimal::feeFromGross($gross, PercentDecimal::normalize($percent), $fixed);

        return [
            'fee' => $amounts['fee'],
            'net' => $amounts['net'],
            'gross' => $gross,
            'percent' => $percent,
            'fixed' => $fixed,
        ];
    }

    /**
     * Taxa sobre valor solicitado de saque (bruto).
     *
     * @return array{fee: float, net: float, gross: float}
     */
    public static function calculateWithdrawalFee(int $tenantId, float $requestedAmount): array
    {
        $rules = self::forTenant($tenantId);
        $percent = PercentDecimal::toFloat(PercentDecimal::normalize($rules['withdrawal']['percent'] ?? 0));
        $fixed = round((float) ($rules['withdrawal']['fixed'] ?? 0), 2);
        $amounts = PercentDecimal::feeFromGross($requestedAmount, PercentDecimal::normalize($percent), $fixed);

        return [
            'fee' => $amounts['fee'],
            'net' => $amounts['net'],
            'gross' => $requestedAmount,
        ];
    }

    /**
     * Menor valor bruto (solicitado) tal que o líquido após taxa da plataforma seja >= targetNet.
     */
    public static function minimumWithdrawalGrossForTargetNet(int $tenantId, float $targetNet): ?float
    {
        if ($targetNet <= 0) {
            return 0.01;
        }

        $rules = self::forTenant($tenantId);
        $p = (float) $rules['withdrawal']['percent'];
        $f = (float) $rules['withdrawal']['fixed'];
        $rate = 1 - ($p / 100.0);
        if ($rate <= 0) {
            return null;
        }

        $g = max(0.01, round(($targetNet + $f) / $rate, 2));

        for ($i = 0; $i < 500000; $i++) {
            $calc = self::calculateWithdrawalFee($tenantId, $g);
            if ($calc['net'] + 0.0001 >= $targetNet) {
                return round($g, 2);
            }
            $g = round($g + 0.01, 2);
        }

        return null;
    }
}
