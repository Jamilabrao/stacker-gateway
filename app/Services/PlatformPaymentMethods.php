<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Formas de pagamento habilitadas globalmente pela plataforma (admin / Financeiro).
 * Infoprodutores podem restringir ainda mais por produto em checkout_config.payment_methods_enabled.
 */
class PlatformPaymentMethods
{
    /** @var list<string> */
    public const METHOD_KEYS = [
        'pix',
        'card',
        'boleto',
        'pix_auto',
        'apple_pay',
        'google_pay',
    ];

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::METHOD_KEYS as $key) {
            $out[$key] = true;
        }

        return $out;
    }

    /**
     * @return array<string, bool>
     */
    public static function platformEnabled(): array
    {
        $raw = Setting::get('platform_payment_methods_enabled', null, null);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $base = self::defaults();
        if (! is_array($raw)) {
            return $base;
        }
        foreach (self::METHOD_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $base[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $base;
    }

    public static function isEnabled(string $methodKey): bool
    {
        $all = self::platformEnabled();

        return ($all[$methodKey] ?? true) !== false;
    }

    /**
     * Produto pode desabilitar; plataforma é o teto (se plataforma desligou, produto não reativa).
     *
     * @param  array<string, bool>  $productEnabled
     */
    public static function isEnabledForCheckout(string $methodKey, array $productEnabled): bool
    {
        if (! self::isEnabled($methodKey)) {
            return false;
        }

        return ($productEnabled[$methodKey] ?? true) !== false;
    }

    /**
     * @return array<int, array{key: string, label: string, hint: string}>
     */
    public static function labelsForAdmin(): array
    {
        return [
            ['key' => 'pix', 'label' => 'PIX', 'hint' => 'QR Code e copia e cola'],
            ['key' => 'card', 'label' => 'Cartão de crédito', 'hint' => 'Checkout com cartão'],
            ['key' => 'boleto', 'label' => 'Boleto', 'hint' => 'Boleto bancário'],
            ['key' => 'pix_auto', 'label' => 'PIX automático', 'hint' => 'Assinaturas com débito recorrente'],
            ['key' => 'apple_pay', 'label' => 'Apple Pay', 'hint' => 'Wallet via CajuPay (iOS)'],
            ['key' => 'google_pay', 'label' => 'Google Pay', 'hint' => 'Wallet via CajuPay (Android/desktop)'],
        ];
    }
}
