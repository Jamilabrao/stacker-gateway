<?php

namespace App\Support;

use App\Models\Setting;

class PanelColorScheme
{
    public const KEY = 'panel_color_scheme';

    public const MODE_SYSTEM = 'system';

    public const MODE_LIGHT = 'light';

    public const MODE_DARK = 'dark';

    /**
     * @return list<string>
     */
    public static function allowedModes(): array
    {
        return [self::MODE_SYSTEM, self::MODE_LIGHT, self::MODE_DARK];
    }

    /**
     * @return array{mode: string, locked: bool}
     */
    public static function defaults(): array
    {
        return [
            'mode' => self::MODE_DARK,
            'locked' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{mode: string, locked: bool}
     */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();
        $mode = self::resolveMode($input['mode'] ?? null);
        $locked = filter_var($input['locked'] ?? $defaults['locked'], FILTER_VALIDATE_BOOL);

        return [
            'mode' => $mode,
            'locked' => $locked,
        ];
    }

    public static function resolveMode(?string $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($value, self::allowedModes(), true) ? $value : self::defaults()['mode'];
    }

    /**
     * @return array{mode: string, locked: bool}
     */
    public static function current(): array
    {
        $raw = Setting::get(self::KEY, null, null);
        if ($raw === null || $raw === '') {
            return self::defaults();
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::normalize($decoded);
            }
        }

        if (is_array($raw)) {
            return self::normalize($raw);
        }

        return self::defaults();
    }

    public static function applyToConfig(): void
    {
        config(['getfy.panel_color_scheme' => self::current()]);
    }

    /**
     * @param  array{mode: string, locked: bool}  $scheme
     */
    public static function resolveIsDark(array $scheme, ?string $storedTheme = null, ?bool $systemPrefersDark = false): bool
    {
        $scheme = self::normalize($scheme);
        $mode = $scheme['mode'];
        $locked = $scheme['locked'];

        if ($locked) {
            if ($mode === self::MODE_SYSTEM) {
                return (bool) $systemPrefersDark;
            }

            return $mode === self::MODE_DARK;
        }

        if ($storedTheme === self::MODE_LIGHT || $storedTheme === self::MODE_DARK) {
            return $storedTheme === self::MODE_DARK;
        }

        if ($mode === self::MODE_SYSTEM) {
            return (bool) $systemPrefersDark;
        }

        return $mode === self::MODE_DARK;
    }

    public static function showToggler(array $scheme): bool
    {
        return ! self::normalize($scheme)['locked'];
    }
}
