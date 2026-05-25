<?php

namespace App\Support;

/**
 * URLs públicas e configuração de discos S3-compatíveis (R2, Wasabi, AWS).
 */
final class RemoteStorage
{
    public static function normalizePublicBaseUrl(string $url): string
    {
        $url = trim($url);

        return $url === '' ? '' : rtrim($url, '/');
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

        return $base.'/'.$key;
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
