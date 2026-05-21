<?php

namespace App\Services;

use App\Models\Setting;

class PhysicalProductAccess
{
    public const SETTING_KEY = 'physical_products_enabled';

    public static function globalEnabled(): bool
    {
        $raw = Setting::get(self::SETTING_KEY, '0', null);

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, array{label: string, description: string, available: bool}>
     */
    public static function filterTypeConfig(array $typeConfig): array
    {
        if (self::globalEnabled()) {
            return $typeConfig;
        }

        unset($typeConfig[\App\Models\Product::TYPE_PRODUTO_FISICO]);

        return $typeConfig;
    }
}
