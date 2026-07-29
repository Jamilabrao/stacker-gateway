<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Botão flutuante de suporte no painel do infoprodutor (config global da plataforma).
 */
final class SellerPanelSupportSettings
{
    public const DEST_WHATSAPP = 'whatsapp';

    public const DEST_URL = 'url';

    public const ICON_WHATSAPP = 'whatsapp';

    public const ICON_MESSAGE = 'message';

    public const ICON_HEADSET = 'headset';

    public const ICON_HELP = 'help';

    public const ICON_CUSTOM = 'custom';

    public const DEFAULT_COLOR = '#25D366';

    /** @var list<string> */
    public const ALLOWED_ICONS = [
        self::ICON_WHATSAPP,
        self::ICON_MESSAGE,
        self::ICON_HEADSET,
        self::ICON_HELP,
        self::ICON_CUSTOM,
    ];

    public static function isEnabled(): bool
    {
        $value = Setting::get('seller_panel_support_enabled', '0', null);

        return $value === '1'
            || $value === 1
            || $value === true
            || $value === 'true';
    }

    public static function destination(): string
    {
        $value = (string) Setting::get('seller_panel_support_destination', self::DEST_WHATSAPP, null);

        return in_array($value, [self::DEST_WHATSAPP, self::DEST_URL], true)
            ? $value
            : self::DEST_WHATSAPP;
    }

    public static function whatsappNumber(): string
    {
        return (string) Setting::get('seller_panel_support_whatsapp', '', null);
    }

    public static function customUrl(): string
    {
        return (string) Setting::get('seller_panel_support_url', '', null);
    }

    public static function icon(): string
    {
        $value = (string) Setting::get('seller_panel_support_icon', self::ICON_WHATSAPP, null);

        return in_array($value, self::ALLOWED_ICONS, true) ? $value : self::ICON_WHATSAPP;
    }

    public static function iconImageUrl(): string
    {
        $stored = (string) Setting::get('seller_panel_support_icon_image', '', null);
        if ($stored === '') {
            return '';
        }

        // Asset global da plataforma: não usar o storage do tenant logado (infoprodutor).
        return (new \App\Services\StorageService(null))->resolvePublicUrl($stored);
    }

    public static function color(): string
    {
        $value = trim((string) Setting::get('seller_panel_support_color', self::DEFAULT_COLOR, null));
        if ($value === '' || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            return self::DEFAULT_COLOR;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            'seller_panel_support_enabled' => self::isEnabled() ? '1' : '0',
            'seller_panel_support_destination' => self::destination(),
            'seller_panel_support_whatsapp' => self::whatsappNumber(),
            'seller_panel_support_url' => self::customUrl(),
            'seller_panel_support_icon' => self::icon(),
            'seller_panel_support_icon_image' => self::iconImageUrl(),
            'seller_panel_support_color' => self::color(),
        ];
    }

    /**
     * @return array{enabled: bool, href: ?string, icon: string, icon_url: ?string, color: string}
     */
    public static function publicConfig(): array
    {
        $base = [
            'enabled' => false,
            'href' => null,
            'icon' => self::icon(),
            'icon_url' => null,
            'color' => self::color(),
        ];

        if (! self::isEnabled()) {
            return $base;
        }

        $href = self::resolveHref();
        if ($href === null) {
            return $base;
        }

        $icon = self::icon();
        $iconUrl = null;
        if ($icon === self::ICON_CUSTOM) {
            $iconUrl = self::resolveCustomIconUrlForPanel();
            if ($iconUrl === null || $iconUrl === '') {
                $icon = self::ICON_WHATSAPP;
                $iconUrl = null;
            }
        }

        return [
            'enabled' => true,
            'href' => $href,
            'icon' => $icon,
            'icon_url' => $iconUrl,
            'color' => self::color(),
        ];
    }

    /**
     * URL do avatar customizado para o painel do info.
     * Usa o mesmo resolve do admin (storage global da plataforma + host da request atual).
     */
    public static function resolveCustomIconUrlForPanel(): ?string
    {
        $url = trim(self::iconImageUrl());
        if ($url === '') {
            return null;
        }

        if (SafeUrl::isAllowedHttpUrl($url) || str_starts_with($url, '/storage/') || str_starts_with($url, '/images/')) {
            return $url;
        }

        // Path relativo gravado no settings — re-resolve com disco da plataforma (tenant null).
        $resolved = (new \App\Services\StorageService(null))->resolvePublicUrl($url);

        return $resolved !== '' ? $resolved : null;
    }

    public static function normalizeWhatsappNumber(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (strlen($digits) < 10) {
            return '';
        }

        if (strlen($digits) <= 11 && ! str_starts_with($digits, '55')) {
            $digits = '55'.$digits;
        }

        return $digits;
    }

    public static function buildWhatsAppHref(?string $phone): ?string
    {
        $digits = self::normalizeWhatsappNumber($phone);
        if ($digits === '') {
            return null;
        }

        return 'https://wa.me/'.$digits;
    }

    public static function normalizeIcon(?string $icon): string
    {
        $icon = (string) $icon;

        return in_array($icon, self::ALLOWED_ICONS, true) ? $icon : self::ICON_WHATSAPP;
    }

    public static function normalizeColor(?string $color): string
    {
        $color = trim((string) $color);
        if ($color === '' || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return self::DEFAULT_COLOR;
        }

        return $color;
    }

    public static function persistFromValidated(array $validated): void
    {
        if (array_key_exists('seller_panel_support_enabled', $validated)) {
            $enabled = ($validated['seller_panel_support_enabled'] ?? false) === true
                || $validated['seller_panel_support_enabled'] === '1'
                || $validated['seller_panel_support_enabled'] === 1;
            Setting::set('seller_panel_support_enabled', $enabled ? '1' : '0', null);
        }

        if (array_key_exists('seller_panel_support_destination', $validated)) {
            $dest = (string) ($validated['seller_panel_support_destination'] ?? self::DEST_WHATSAPP);
            Setting::set(
                'seller_panel_support_destination',
                in_array($dest, [self::DEST_WHATSAPP, self::DEST_URL], true) ? $dest : self::DEST_WHATSAPP,
                null
            );
        }

        if (array_key_exists('seller_panel_support_whatsapp', $validated)) {
            Setting::set(
                'seller_panel_support_whatsapp',
                self::normalizeWhatsappNumber($validated['seller_panel_support_whatsapp'] ?? ''),
                null
            );
        }

        if (array_key_exists('seller_panel_support_url', $validated)) {
            $url = SafeUrl::normalizeHttpUrl($validated['seller_panel_support_url'] ?? null);
            Setting::set('seller_panel_support_url', $url ?? '', null);
        }

        if (array_key_exists('seller_panel_support_icon', $validated)) {
            Setting::set(
                'seller_panel_support_icon',
                self::normalizeIcon($validated['seller_panel_support_icon'] ?? null),
                null
            );
        }

        if (array_key_exists('seller_panel_support_color', $validated)) {
            Setting::set(
                'seller_panel_support_color',
                self::normalizeColor($validated['seller_panel_support_color'] ?? null),
                null
            );
        }
    }

    public static function resolveHref(): ?string
    {
        if (self::destination() === self::DEST_URL) {
            return SafeUrl::normalizeHttpUrl(self::customUrl());
        }

        return self::buildWhatsAppHref(self::whatsappNumber());
    }
}
