<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\StorageService;

final class DashboardBannerSettings
{
    public const KEY = 'dashboard_banners';

    /**
     * @return array<int, array{id:string,title:string,desktop_url:string,mobile_url:string,active:bool,sort_order:int}>
     */
    public static function banners(bool $activeOnly = false, bool $resolveUrls = true): array
    {
        $raw = Setting::get(self::KEY, [], null);
        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($rows)) {
            return [];
        }

        $storage = $resolveUrls ? app(StorageService::class) : null;

        $items = collect($rows)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item, int $idx) use ($storage, $resolveUrls) {
                $desktop = (string) ($item['desktop_url'] ?? '');
                $mobile = (string) ($item['mobile_url'] ?? '');

                if ($resolveUrls && $storage !== null) {
                    $desktop = $desktop !== '' ? $storage->resolvePublicUrl($desktop) : '';
                    $mobile = $mobile !== '' ? $storage->resolvePublicUrl($mobile) : '';
                }

                return [
                    'id' => (string) ($item['id'] ?? ('banner-'.$idx)),
                    'title' => (string) ($item['title'] ?? ''),
                    'desktop_url' => $desktop,
                    'mobile_url' => $mobile,
                    'active' => (bool) ($item['active'] ?? true),
                    'sort_order' => (int) ($item['sort_order'] ?? ($idx + 1)),
                ];
            })
            ->sortBy('sort_order')
            ->values();

        if ($activeOnly) {
            $items = $items->filter(
                fn (array $item) => $item['active'] && ($item['desktop_url'] !== '' || $item['mobile_url'] !== '')
            );
        }

        return $items->all();
    }
}
