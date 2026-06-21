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
        $checkout = CheckoutTurnstileSettings::publicConfig();

        return [
            'enabled' => $flagOn && $checkout['site_key'] !== '' && CheckoutTurnstileSettings::secretKey() !== '',
            'site_key' => $checkout['site_key'],
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
        $public = self::publicConfig();
        $checkoutKeysConfigured = CheckoutTurnstileSettings::secretKey() !== ''
            && trim((string) Setting::get('checkout_turnstile_site_key', '', null)) !== '';

        return [
            'registration_turnstile_enabled' => Setting::get('registration_turnstile_enabled', '0', null) === '1' ? '1' : '0',
            'registration_turnstile_available' => $checkoutKeysConfigured,
        ];
    }
}
