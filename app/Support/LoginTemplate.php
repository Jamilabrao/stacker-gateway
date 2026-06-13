<?php

namespace App\Support;

use App\Models\Setting;

class LoginTemplate
{
    public const KEY = 'login_template';

    public const DEFAULT = 'default';

    public const SPOTLIGHT = 'spotlight';

    public const IMMERSIVE = 'immersive';

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::DEFAULT, self::SPOTLIGHT, self::IMMERSIVE];
    }

    public static function resolve(?string $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($value, self::allowed(), true) ? $value : self::DEFAULT;
    }

    public static function current(): string
    {
        $raw = Setting::get(self::KEY, self::DEFAULT, null);

        return self::resolve(is_string($raw) ? $raw : null);
    }
}
