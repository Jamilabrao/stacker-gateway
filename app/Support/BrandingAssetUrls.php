<?php

namespace App\Support;

use App\Services\StorageService;

final class BrandingAssetUrls
{
    public const IMAGE_KEYS = [
        'app_logo',
        'app_logo_dark',
        'app_logo_icon',
        'app_logo_icon_dark',
        'pwa_nav_logo',
        'pwa_nav_logo_dark',
        'login_hero_image',
        'favicon_url',
        'pwa_icon_192',
        'pwa_icon_512',
    ];

    public static function resolve(?string $stored): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }

        $stored = trim($stored);

        if (str_starts_with($stored, '/images/') || str_starts_with($stored, '/icons/')) {
            return self::absolutePath($stored);
        }

        if (preg_match('#^https?://#i', $stored)) {
            $normalizer = new \App\Services\StorageUrlNormalizer;
            if ($normalizer->isLocalStorageUrl($stored)) {
                return app(StorageService::class)->resolvePublicUrl($stored);
            }

            return self::upgradeInsecureUrl($stored);
        }

        return app(StorageService::class)->resolvePublicUrl($stored);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolveData(array $data): array
    {
        foreach (self::IMAGE_KEYS as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') {
                continue;
            }
            $data[$key] = self::resolve($data[$key]);
        }

        return $data;
    }

    public static function storePathFromUpload(string $path): string
    {
        return RemoteStorage::normalizeObjectKey($path);
    }

    private static function absolutePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return app(StorageService::class)->resolvePublicUrl($path);
    }

    private static function upgradeInsecureUrl(string $url): string
    {
        if (! app()->runningInConsole()) {
            $request = request();
            if ($request && $request->isSecure() && str_starts_with(strtolower($url), 'http://')) {
                return 'https://'.substr($url, 7);
            }
        }

        return $url;
    }
}
