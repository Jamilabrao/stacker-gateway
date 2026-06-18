<?php

namespace App\Services;

use App\Models\Setting;

class ApiPixAccess
{
    public const MODE_INHERIT = 'inherit';

    public const MODE_ENABLED = 'enabled';

    public const MODE_DISABLED = 'disabled';

    public static function globalEnabled(): bool
    {
        $raw = Setting::get('api_pix_enabled', '1', null);

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function tenantOverride(?int $tenantId): ?bool
    {
        if ($tenantId === null) {
            return null;
        }

        $row = Setting::query()
            ->where('key', 'api_pix_enabled')
            ->where('tenant_id', $tenantId)
            ->first();

        if ($row === null) {
            return null;
        }

        return filter_var($row->value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function tenantMode(?int $tenantId): string
    {
        $override = self::tenantOverride($tenantId);
        if ($override === null) {
            return self::MODE_INHERIT;
        }

        return $override ? self::MODE_ENABLED : self::MODE_DISABLED;
    }

    public static function setTenantMode(int $tenantId, string $mode): void
    {
        if ($mode === self::MODE_INHERIT) {
            Setting::query()
                ->where('key', 'api_pix_enabled')
                ->where('tenant_id', $tenantId)
                ->delete();

            return;
        }

        Setting::set('api_pix_enabled', $mode === self::MODE_ENABLED, $tenantId);
    }

    public static function effectiveForTenant(?int $tenantId): bool
    {
        $override = self::tenantOverride($tenantId);
        if ($override !== null) {
            return $override;
        }

        return self::globalEnabled();
    }
}
