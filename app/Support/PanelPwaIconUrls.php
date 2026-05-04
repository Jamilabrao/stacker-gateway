<?php

namespace App\Support;

/**
 * Ícones do PWA do painel — mesma prioridade que {@see \App\Http\Controllers\PanelPwaController::manifest}.
 */
final class PanelPwaIconUrls
{
    /**
     * Pares (src absoluto, sizes) na mesma ordem do manifest antes de `purpose`/duplicação.
     *
     * @return list<array{src: string, sizes: string}>
     */
    public static function manifestIconSpecs(): array
    {
        $specs = [];

        $pwa192 = is_string($v = config('getfy.pwa_icon_192')) ? trim($v) : '';
        $pwa512 = is_string($v = config('getfy.pwa_icon_512')) ? trim($v) : '';
        $has192 = $pwa192 !== '';
        $has512 = $pwa512 !== '';

        if ($has192 || $has512) {
            if ($has192 && $has512) {
                $specs[] = ['src' => self::toAbsoluteUrl($pwa192), 'sizes' => '192x192'];
                $specs[] = ['src' => self::toAbsoluteUrl($pwa512), 'sizes' => '512x512'];
            } elseif ($has192) {
                $specs[] = ['src' => self::toAbsoluteUrl($pwa192), 'sizes' => '192x192'];
                $specs[] = ['src' => self::toAbsoluteUrl($pwa192), 'sizes' => '512x512'];
            } else {
                $specs[] = ['src' => self::toAbsoluteUrl($pwa512), 'sizes' => '512x512'];
                $specs[] = ['src' => self::toAbsoluteUrl($pwa512), 'sizes' => '192x192'];
            }

            return $specs;
        }

        $iconsDir = public_path('icons');
        $file192 = is_file($iconsDir.'/icon-192x192.png');
        $file512 = is_file($iconsDir.'/icon-512x512.png');
        $icon192Url = url('/icons/icon-192x192.png');
        $icon512Url = url('/icons/icon-512x512.png');

        if ($file192) {
            $specs[] = ['src' => $icon192Url, 'sizes' => '192x192'];
        }
        if ($file512) {
            $specs[] = ['src' => $icon512Url, 'sizes' => '512x512'];
        }
        if ($specs === []) {
            $fallbackIcon = self::toAbsoluteUrl((string) config('getfy.app_logo_icon', 'https://cdn.getfy.cloud/collapsed-logo.png'));
            $specs[] = ['src' => $fallbackIcon, 'sizes' => '192x192'];
            $specs[] = ['src' => $fallbackIcon, 'sizes' => '512x512'];

            return $specs;
        }
        if ($file512 && ! $file192) {
            $specs[] = ['src' => $icon512Url, 'sizes' => '192x192'];
        }
        if ($file192 && ! $file512) {
            $specs[] = ['src' => $icon192Url, 'sizes' => '512x512'];
        }

        return $specs;
    }

    /** URL única para icon/badge de Web Push (prefere entrada 192x192). */
    public static function primaryNotificationIconUrl(): string
    {
        $specs = self::manifestIconSpecs();
        foreach ($specs as $spec) {
            if (($spec['sizes'] ?? '') === '192x192') {
                return $spec['src'];
            }
        }

        return $specs[0]['src'] ?? url('/icons/icon-192x192.png');
    }

    public static function withVersion(string $src, ?string $v): string
    {
        $src = trim($src);
        if ($src === '' || $v === null || $v === '') {
            return $src;
        }
        if (str_contains($src, 'v=')) {
            return $src;
        }

        return str_contains($src, '?') ? ($src.'&v='.$v) : ($src.'?v='.$v);
    }

    private static function toAbsoluteUrl(string $src): string
    {
        $src = trim($src);
        if ($src === '') {
            return $src;
        }
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }
        if (str_starts_with($src, '//')) {
            $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$src;
        }

        return url($src);
    }
}
