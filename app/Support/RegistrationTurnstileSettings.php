<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Configuração global do Turnstile no cadastro de infoprodutores (painel plataforma).
 * Reutiliza site/secret keys do checkout.
 */
final class RegistrationTurnstileSettings
{
    /**
     * @return array{enabled: bool, site_key: string}
     */
    public static function publicConfig(): array
    {
        $flagOn = Setting::get('registration_turnstile_enabled', '0', null) === '1';

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
            'registration_turnstile_enabled' => Setting::get('registration_turnstile_enabled', '0', null) === '1' ? '1' : '0',
            'turnstile_keys_configured' => CheckoutTurnstileSettings::keysConfigured(),
        ];
    }
}
