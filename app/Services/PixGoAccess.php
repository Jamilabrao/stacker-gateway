<?php

namespace App\Services;

use App\Models\Setting;

class PixGoAccess
{
    public const SETTING_ENABLED = 'pixgo_enabled';

    public const SETTING_SIDEBAR_LABEL = 'pixgo_sidebar_label';

    public const DEFAULT_SIDEBAR_LABEL = 'PixGO';

    public static function globalEnabled(): bool
    {
        $raw = Setting::get(self::SETTING_ENABLED, '0', null);

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function sidebarLabel(): string
    {
        $label = trim((string) Setting::get(self::SETTING_SIDEBAR_LABEL, self::DEFAULT_SIDEBAR_LABEL, null));

        return $label !== '' ? $label : self::DEFAULT_SIDEBAR_LABEL;
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::set(self::SETTING_ENABLED, $enabled ? '1' : '0', null);
    }

    public static function setSidebarLabel(string $label): void
    {
        $trimmed = trim($label);
        Setting::set(
            self::SETTING_SIDEBAR_LABEL,
            $trimmed !== '' ? $trimmed : self::DEFAULT_SIDEBAR_LABEL,
            null
        );
    }
}
