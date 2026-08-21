<?php

namespace App\Services\Payout;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Services\CajuPay\CajuPayAccountResolver;

/**
 * Provedor de payout da plataforma (saque automático PIX).
 *
 * Gateways com API de cashout: CajuPay, Woovi, BSPay, Versell e OnlyUp (plugin).
 * Preferência configurável em {@see Setting} `platform_payout_gateway`
 * (`auto`, `cajupay`, `woovi`, `bspay`, `versell`, `onlyup`).
 * Em `auto`, a ordem fixa é CajuPay → Woovi → BSPay → Versell → OnlyUp — o primeiro globalmente conectado vence.
 * Spacepag permanece no código de payout legado, mas está fora da UI e da ordem automática.
 */
class PlatformPayoutGateway
{
    /** @var list<string> */
    public const PAYOUT_ORDER = ['cajupay', /* 'spacepag', */ 'woovi', 'bspay', 'versell', 'onlyup'];

    /**
     * Preferência salva no painel: automático ou forçar um dos gateways.
     *
     * @return 'auto'|'cajupay'|'woovi'|'bspay'|'versell'|'onlyup'
     */
    public static function preference(): string
    {
        $v = Setting::get('platform_payout_gateway', null, null);
        if (in_array($v, ['cajupay', /* 'spacepag', */ 'woovi', 'bspay', 'versell', 'onlyup'], true)) {
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
            if ($cred === null || ! $cred->is_connected) {
                continue;
            }
            if ($slug === 'versell') {
                $creds = $cred->getDecryptedCredentials();
                if (! \App\Gateways\Versell\VersellCredentials::isCashOutReady($creds)) {
                    continue;
                }
            }
            $connected[$slug] = true;
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
