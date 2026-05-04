<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Ícone para Web Push na área de membros — alinhado a {@see \App\Http\Controllers\MemberAreaAppController::manifest}.
 */
final class MemberAreaPwaIconUrls
{
    public static function notificationIconUrl(Request $request, Product $product): string
    {
        if ($product->type !== Product::TYPE_AREA_MEMBROS) {
            return self::fallbackSvg($request);
        }

        $config = $product->member_area_config;
        $pwa = $config['pwa'] ?? [];
        $logos = $config['logos'] ?? [];

        $faviconUrl = $logos['favicon'] ?? $pwa['favicon'] ?? null;
        if (is_string($faviconUrl) && trim($faviconUrl) !== '') {
            return self::toAbsolute($request, trim($faviconUrl));
        }

        if (isset($pwa['icons']) && is_array($pwa['icons'])) {
            $resolved = [];
            foreach ($pwa['icons'] as $icon) {
                $src = $icon['src'] ?? null;
                if (! is_string($src) || trim($src) === '') {
                    continue;
                }
                $abs = self::toAbsolute($request, trim($src));
                $type = isset($icon['type']) && is_string($icon['type']) ? $icon['type'] : '';
                $resolved[] = ['src' => $abs, 'type' => $type];
            }
            foreach ($resolved as $row) {
                if (str_contains(strtolower($row['type']), 'png') || str_ends_with(strtolower(parse_url($row['src'], PHP_URL_PATH) ?: ''), '.png')) {
                    return $row['src'];
                }
            }
            if ($resolved !== []) {
                return $resolved[0]['src'];
            }
        }

        return self::fallbackSvg($request);
    }

    private static function fallbackSvg(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/').'/images/gateways/pix.svg';
    }

    private static function toAbsolute(Request $request, string $src): string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }
        $host = rtrim($request->getSchemeAndHttpHost(), '/');
        if (str_starts_with($src, '/')) {
            return $host.$src;
        }

        return $host.'/'.ltrim($src, '/');
    }
}
