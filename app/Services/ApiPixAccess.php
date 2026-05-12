<?php

namespace App\Services;

use App\Models\Setting;

class ApiPixAccess
{
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

    public static function effectiveForTenant(?int $tenantId): bool
    {
        $override = self::tenantOverride($tenantId);
        if ($override !== null) {
            return $override;
        }

        return self::globalEnabled();
    }
}
