<?php

namespace App\Support;

/**
 * URLs públicas e configuração de discos S3-compatíveis (R2, Wasabi, AWS).
 */
final class RemoteStorage
{
    /**
     * Garante URL absoluta com esquema (evita media.dominio.com/arquivo ser tratado como path relativo no HTML).
     */
    public static function ensureAbsoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}#i', $url)) {
            return 'https://'.ltrim($url, '/');
        }

        return $url;
    }

    public static function normalizePublicBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return rtrim(self::ensureAbsoluteUrl($url), '/');
    }

    public static function isR2ApiEndpoint(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        return str_contains(strtolower($url), 'r2.cloudflarestorage.com');
    }

    /**
     * URL gerada pelo adapter sem base pública configurada (inacessível no browser).
     */
    public static function isLikelyNonPublicUrl(string $url): bool
    {
        if (self::isR2ApiEndpoint($url)) {
            return true;
        }

        return (bool) preg_match('#\.r2\.cloudflarestorage\.com#i', $url);
    }

    /**
     * Extrai a chave do objeto a partir de URL completa (endpoint API, CDN ou /storage/ local).
     */
    public static function extractObjectKeyFromUrl(string $url, ?string $bucket = null): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/storage/')) {
            return ltrim(substr($url, strlen('/storage/')), '/') ?: null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            return ltrim($url, '/') ?: null;
        }

        $parsed = parse_url($url);
        $path = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';
        if ($path === '') {
            return null;
        }

        $bucket = $bucket !== null && $bucket !== '' ? trim($bucket) : null;
        if ($bucket !== null && str_starts_with($path, $bucket.'/')) {
            return substr($path, strlen($bucket) + 1) ?: null;
        }

        return $path;
    }

    public static function buildPublicUrl(string $baseUrl, string $objectKey): string
    {
        $base = self::normalizePublicBaseUrl($baseUrl);
        $key = ltrim($objectKey, '/');
        if ($base === '' || $key === '') {
            return '';
        }

        return self::ensureAbsoluteUrl($base.'/'.$key);
    }

    /**
     * Corrige URLs geradas por engano quando a base pública não tinha https:// (ex.: /plataforma/media.site.com/...).
     */
    public static function repairEmbeddedPublicHostUrl(string $stored, string $publicBaseUrl): ?string
    {
        $publicBaseUrl = self::normalizePublicBaseUrl($publicBaseUrl);
        if ($publicBaseUrl === '' || ! preg_match('#^https?://#i', $stored)) {
            return null;
        }

        $host = parse_url($publicBaseUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        if (! str_contains(strtolower($stored), strtolower($host))) {
            return null;
        }

        if (str_starts_with(strtolower($stored), strtolower($publicBaseUrl.'/')) {
            return null;
        }

        if (preg_match('#(?:https?://)?'.preg_quote($host, '#').'/(.+)$#i', $stored, $m)) {
            return self::buildPublicUrl($publicBaseUrl, $m[1]);
        }

        return null;
    }

    /**
     * @param  array{key: string, secret: string, bucket: string, region: string, endpoint: string, url: string, provider: string}  $creds
     * @return array<string, mixed>
     */
    public static function buildS3DiskConfig(array $creds): array
    {
        $provider = $creds['provider'] ?? 's3';
        $endpoint = trim((string) ($creds['endpoint'] ?? ''));
        $region = trim((string) ($creds['region'] ?? 'us-east-1'));
        $isR2 = $provider === 'r2' || self::isR2ApiEndpoint($endpoint);
        $regionForConfig = $isR2 ? 'auto' : ($region !== '' ? $region : 'us-east-1');

        $config = [
            'driver' => 's3',
            'key' => $creds['key'],
            'secret' => $creds['secret'],
            'region' => $regionForConfig,
            'bucket' => $creds['bucket'],
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ];

        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = self::isR2ApiEndpoint($endpoint)
                || str_contains($endpoint, 'wasabisys.com')
                || str_contains($endpoint, 'digitaloceanspaces.com');
        }

        $publicUrl = self::normalizePublicBaseUrl((string) ($creds['url'] ?? ''));
        if ($publicUrl !== '') {
            $config['url'] = $publicUrl;
        }

        return $config;
    }

    /**
     * R2 precisa de URL pública (pub-*.r2.dev ou domínio customizado); endpoint S3 API não serve imagens no browser.
     */
    public static function requiresPublicBaseUrl(string $provider): bool
    {
        return $provider === 'r2';
    }

    public static function resolvePublicBaseUrlForProvider(string $provider, string $settingsUrl, array $r2Env): string
    {
        $fromSettings = self::normalizePublicBaseUrl($settingsUrl);
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        if ($provider === 'r2' && ! empty($r2Env['configured'])) {
            return self::normalizePublicBaseUrl((string) ($r2Env['url'] ?? ''));
        }

        return '';
    }
}
