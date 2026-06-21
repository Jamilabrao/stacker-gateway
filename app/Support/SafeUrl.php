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
     * URL de imagem do app: http(s) absoluto ou path /storage/... do mesmo site.
     */
    public static function normalizeAppImageUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $http = self::normalizeHttpUrl($url);
        if ($http !== null) {
            return $http;
        }

        if (str_starts_with($url, '/storage/')) {
            return url($url);
        }

        return null;
    }

    /**
     * Retorna URL segura ou null se inválida.
     */
    public static function normalizeHttpUrl(?string $url): ?string
    {
        return self::isAllowedHttpUrl($url) ? trim((string) $url) : null;
    }

    /**
     * Redirect pós-compra: path interno (/obrigado) ou URL http(s) absoluta.
     */
    public static function normalizeCheckoutRedirect(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $absolute = self::normalizeHttpUrl($url);
        if ($absolute !== null) {
            return $absolute;
        }

        if (preg_match('#^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}([/?#].*)?$#i', $url)) {
            return self::normalizeHttpUrl('https://'.$url);
        }

        return null;
    }
}
