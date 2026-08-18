<?php

namespace App\Services\Payout;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Services\CajuPay\CajuPayAccountResolver;

/**
 * Provedor de payout da plataforma (saque automático PIX).
 *
     * Gateways com API de cashout: CajuPay, Spacepag, Woovi, BSPay e OnlyUp (plugin).
     * Preferência configurável em {@see Setting} `platform_payout_gateway` (`auto`, `cajupay`, `spacepag`, `woovi`, `bspay`, `onlyup`).
     * Em `auto`, a ordem fixa é CajuPay → Spacepag → Woovi → BSPay → OnlyUp — o primeiro globalmente conectado vence.
     */
    class PlatformPayoutGateway
    {
        /** @var list<string> */
        public const PAYOUT_ORDER = ['cajupay', 'spacepag', 'woovi', 'bspay', 'onlyup'];

        /**
         * Preferência salva no painel: automático ou forçar um dos gateways.
         *
         * @return 'auto'|'cajupay'|'spacepag'|'woovi'|'bspay'|'onlyup'
         */
        public static function preference(): string
        {
            $v = Setting::get('platform_payout_gateway', null, null);
            if (in_array($v, ['cajupay', 'spacepag', 'woovi', 'bspay', 'onlyup'], true)) {
                return $v;
            }

        return 'auto';
    }

    public static function activeSlug(): ?string
    {
        $connected = [];
        foreach (self::PAYOUT_ORDER as $slug) {
            if ($slug === 'cajupay') {
                if (app(CajuPayAccountResolver::class)->anyConnectedForPayout()) {
                    $connected[$slug] = true;
                }
                continue;
            }
            $cred = GatewayCredential::resolveForPayment(null, $slug);
            if ($cred !== null && $cred->is_connected) {
                $connected[$slug] = true;
            }
        }

        if ($connected === []) {
            return null;
        }

        $pref = self::preference();

        if ($pref !== 'auto' && isset($connected[$pref])) {
            return $pref;
        }

        foreach (self::PAYOUT_ORDER as $slug) {
            if (isset($connected[$slug])) {
                return $slug;
            }
        }

        return null;
    }

    public static function isEnabled(): bool
    {
        return self::activeSlug() !== null;
    }
}
