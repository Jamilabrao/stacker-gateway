<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Configuração global do Turnstile no login (/login e /plataforma/login).
 */
final class LoginTurnstileSettings
{
    /**
     * @return array{enabled: bool, site_key: string}
     */
    public static function publicConfig(): array
    {
        $flagOn = Setting::get('login_turnstile_enabled', '0', null) === '1';

        return [
            'enabled' => $flagOn && CheckoutTurnstileSettings::keysConfigured(),
            'site_key' => CheckoutTurnstileSettings::siteKey(),
        ];
    }

    public static function isRequired(): bool
    {
        return self::publicConfig()['enabled'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            'login_turnstile_enabled' => Setting::get('login_turnstile_enabled', '0', null) === '1' ? '1' : '0',
        ];
    }
}
