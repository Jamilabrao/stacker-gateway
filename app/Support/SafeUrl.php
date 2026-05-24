<?php

namespace App\Support;

final class SafeUrl
{
    /**
     * Permite apenas http/https para links em checkout e configurações.
     */
    public static function isAllowedHttpUrl(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $url = trim($url);
        if ($url === '' || $url === '#') {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $blocked = ['javascript', 'data', 'vbscript', 'file'];
        foreach ($blocked as $bad) {
            if (str_starts_with(strtolower($url), $bad.':')) {
                return false;
            }
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Retorna URL segura ou null se inválida.
     */
    public static function normalizeHttpUrl(?string $url): ?string
    {
        return self::isAllowedHttpUrl($url) ? trim((string) $url) : null;
    }
}
