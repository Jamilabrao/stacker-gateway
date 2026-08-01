<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;

/**
 * Configuração do resumo diário de vendas por push.
 */
final class DailySalesPushSettings
{
    public const KEY_ENABLED = 'daily_sales_push_enabled';

    public const KEY_TIME = 'daily_sales_push_time';

    public const KEY_TIMEZONE = 'daily_sales_push_timezone';

    public const KEY_ONLY_WHEN_HAS_SALES = 'daily_sales_push_only_when_has_sales';

    public static function enabled(): bool
    {
        return Setting::get(self::KEY_ENABLED, '0', null) === '1';
    }

    public static function time(): string
    {
        $time = (string) Setting::get(self::KEY_TIME, '20:00', null);
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return '20:00';
        }

        return $time;
    }

    public static function timezone(): string
    {
        $tz = (string) Setting::get(self::KEY_TIMEZONE, 'America/Sao_Paulo', null);
        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (\Throwable) {
            return 'America/Sao_Paulo';
        }
    }

    public static function onlyWhenHasSales(): bool
    {
        $value = Setting::get(self::KEY_ONLY_WHEN_HAS_SALES, '1', null);

        return $value === null || $value === '1' || $value === true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forAdminForm(): array
    {
        return [
            'daily_sales_push_enabled' => self::enabled() ? '1' : '0',
            'daily_sales_push_time' => self::time(),
            'daily_sales_push_timezone' => self::timezone(),
            'daily_sales_push_only_when_has_sales' => self::onlyWhenHasSales() ? '1' : '0',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persist(array $validated, ?Request $request = null): void
    {
        $enabled = filter_var($validated['daily_sales_push_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $only = array_key_exists('daily_sales_push_only_when_has_sales', $validated)
            ? filter_var($validated['daily_sales_push_only_when_has_sales'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $time = (string) ($validated['daily_sales_push_time'] ?? self::time());
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '20:00';
        }
        $tz = (string) ($validated['daily_sales_push_timezone'] ?? self::timezone());
        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            $tz = 'America/Sao_Paulo';
        }

        Setting::set(self::KEY_ENABLED, $enabled ? '1' : '0', null);
        Setting::set(self::KEY_TIME, $time, null);
        Setting::set(self::KEY_TIMEZONE, $tz, null);
        Setting::set(self::KEY_ONLY_WHEN_HAS_SALES, $only ? '1' : '0', null);

        PlatformAuditService::log('push.daily_summary_settings_updated', [
            'enabled' => $enabled,
            'time' => $time,
            'timezone' => $tz,
            'only_when_has_sales' => $only,
        ], $request);
    }
}
