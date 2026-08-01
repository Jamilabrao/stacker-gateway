<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Atribuição automática de gerentes a novos infoprodutores.
 */
final class AccountManagerSettings
{
    public const KEY_MODE = 'account_manager_auto_assign_mode';

    public const MODE_NONE = 'none';

    public const MODE_LEAST_LOAD = 'least_load';

    public static function mode(): string
    {
        $value = (string) Setting::get(self::KEY_MODE, self::MODE_LEAST_LOAD, null);

        return in_array($value, [self::MODE_NONE, self::MODE_LEAST_LOAD], true)
            ? $value
            : self::MODE_LEAST_LOAD;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            self::KEY_MODE => self::mode(),
        ];
    }
}
