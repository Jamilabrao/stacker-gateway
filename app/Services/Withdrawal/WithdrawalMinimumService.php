<?php

namespace App\Services\Withdrawal;

use App\Models\Setting;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Payout\PlatformPayoutGateway;

/**
 * Limite mínimo global de saque (líquido) + piso técnico do gateway de payout.
 *
 * As taxas admin PIX/saque nas credenciais do adquirente NÃO entram no piso do seller:
 * servem para KPIs e para montar o valor enviado na API de cashout.
 */
class WithdrawalMinimumService
{
    public const SETTING = 'platform_minimum_withdrawal_brl';

    public const DEFAULT = 0.0;

    public static function platformMinimumBrl(): float
    {
        $raw = Setting::get(self::SETTING, (string) self::DEFAULT, null);

        return max(0.0, round((float) $raw, 2));
    }

    public static function setPlatformMinimumBrl(float $value): void
    {
        Setting::set(self::SETTING, (string) round(max(0.0, $value), 2), null);
    }

    /**
     * Líquido mínimo efetivo exigido no saque.
     *
     * @param  array{required_min_net?: float}|null  $gatewayEconomics  Economia já resolvida do gateway; null = gateway ativo (se houver)
     */
    public static function effectiveRequiredMinNet(?array $gatewayEconomics = null): float
    {
        $platform = self::platformMinimumBrl();
        $gateway = 0.0;

        if ($gatewayEconomics !== null) {
            $gateway = max(0.0, (float) ($gatewayEconomics['required_min_net'] ?? 0));
        } elseif (PlatformPayoutGateway::isEnabled()) {
            $gateway = max(0.0, (float) (GatewayPayoutEconomics::forActiveGateway()['required_min_net'] ?? 0));
        }

        return round(max($platform, $gateway), 2);
    }
}
