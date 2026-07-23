<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Origem pública da aplicação para links enviados a clientes (e-mail, WhatsApp, etc.).
 * Evita localhost quando o request/queue roda atrás do Docker com APP_URL interno.
 */
final class PublicAppUrl
{
    public static function base(): string
    {
        foreach ([
            config('getfy.webhook_public_url'),
            config('app.url'),
            self::dockerAppUrlOrNull(),
            self::requestRootOrNull(),
        ] as $candidate) {
            $normalized = self::normalize((string) ($candidate ?? ''));
            if ($normalized !== null && ! self::isLocalHost($normalized)) {
                return $normalized;
            }
        }

        return self::normalize((string) config('app.url'))
            ?? self::normalize((string) (self::dockerAppUrlOrNull() ?? ''))
            ?? 'http://localhost';
    }

    /**
     * Scheme + host (+ port) de uma URL, sem path.
     */
    public static function origin(?string $url = null): string
    {
        $url = is_string($url) && trim($url) !== '' ? trim($url) : self::base();
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return self::base();
        }

        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme.'://'.$parts['host'];
        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        if (self::isLocalHost($origin)) {
            $fallback = self::base();
            if (! self::isLocalHost($fallback)) {
                return self::origin($fallback);
            }
        }

        return $origin;
    }

    public static function isLocalHost(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local');
    }

    public static function forceRoot(?string $url = null): void
    {
        $origin = self::origin($url);
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        if (is_string($scheme) && $scheme !== '') {
            URL::forceScheme($scheme);
        }
        URL::forceRootUrl($origin);
    }

    private static function normalize(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    private static function requestRootOrNull(): ?string
    {
        try {
            if (! app()->bound('request')) {
                return null;
            }
            $request = request();
            if (! $request || ! method_exists($request, 'getSchemeAndHttpHost')) {
                return null;
            }
            $host = (string) $request->getHost();
            if ($host === '') {
                return null;
            }

            return $request->getSchemeAndHttpHost();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function dockerAppUrlOrNull(): ?string
    {
        try {
            $path = base_path('.docker/app.url');
            if (! is_file($path)) {
                return null;
            }
            $url = trim((string) @file_get_contents($path));

            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
