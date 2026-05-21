<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuração global do Turnstile no checkout (painel plataforma).
 */
final class CheckoutTurnstileSettings
{
    public const MODE_DISABLED = 'disabled';

    public const MODE_PIX_BOLETO = 'pix_boleto';

    public const MODE_ALL_PAYMENTS = 'all_payments';

    public const MODES = [
        self::MODE_DISABLED,
        self::MODE_PIX_BOLETO,
        self::MODE_ALL_PAYMENTS,
    ];

    /**
     * @return array{enabled: bool, site_key: string, mode: string}
     */
    public static function publicConfig(): array
    {
        $enabled = Setting::get('checkout_turnstile_enabled', '0', null) === '1';
        $siteKey = trim((string) Setting::get('checkout_turnstile_site_key', '', null));
        $mode = trim((string) Setting::get('checkout_turnstile_mode', self::MODE_PIX_BOLETO, null));
        if (! in_array($mode, self::MODES, true)) {
            $mode = self::MODE_PIX_BOLETO;
        }

        return [
            'enabled' => $enabled && $siteKey !== '',
            'site_key' => $siteKey,
            'mode' => $mode,
        ];
    }

    public static function isEnabled(): bool
    {
        return self::publicConfig()['enabled'];
    }

    public static function secretKey(): string
    {
        $raw = Setting::get('checkout_turnstile_secret_key', '', null);
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }
        try {
            return trim((string) decrypt($raw));
        } catch (\Throwable) {
            return trim($raw);
        }
    }

    public static function requiresTokenForPaymentMethod(string $paymentMethod): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        $mode = self::publicConfig()['mode'];
        if ($mode === self::MODE_DISABLED) {
            return false;
        }
        if ($mode === self::MODE_ALL_PAYMENTS) {
            return true;
        }

        return in_array(strtolower($paymentMethod), ['pix', 'boleto'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        $public = self::publicConfig();
        $hasSecret = self::secretKey() !== '';

        return [
            'checkout_turnstile_enabled' => $public['enabled'] ? '1' : (Setting::get('checkout_turnstile_enabled', '0', null) === '1' ? '1' : '0'),
            'checkout_turnstile_site_key' => $public['site_key'],
            'checkout_turnstile_mode' => $public['mode'],
            'checkout_turnstile_secret_configured' => $hasSecret,
        ];
    }

    public static function storeSecret(?string $plain): void
    {
        if ($plain === null || trim($plain) === '') {
            return;
        }
        Setting::set('checkout_turnstile_secret_key', encrypt(trim($plain)), null);
    }
}
