<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\StorageService;
use App\Support\DashboardBannerSettings;
use App\Support\DashboardBannerSpecs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardBannerController extends Controller
{
    public function data(): JsonResponse
    {
        return response()->json([
            'banners' => DashboardBannerSettings::banners(activeOnly: false, resolveUrls: true),
            'specs' => DashboardBannerSpecs::toFrontendSpecs(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'banners' => ['required', 'array', 'max:20'],
            'banners.*.id' => ['required', 'string', 'max:50'],
            'banners.*.title' => ['nullable', 'string', 'max:120'],
            'banners.*.desktop_url' => ['nullable', 'string', 'max:2048'],
            'banners.*.mobile_url' => ['nullable', 'string', 'max:2048'],
            'banners.*.active' => ['nullable', 'boolean'],
            'banners.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $normalized = collect($validated['banners'] ?? [])
            ->map(function (array $item, int $idx) {
                return [
                    'id' => (string) ($item['id'] ?? ('banner-'.$idx)),
                    'title' => trim((string) ($item['title'] ?? '')),
                    'desktop_url' => $this->normalizeStoredUrl($item['desktop_url'] ?? ''),
                    'mobile_url' => $this->normalizeStoredUrl($item['mobile_url'] ?? ''),
                    'active' => (bool) ($item['active'] ?? true),
                    'sort_order' => (int) ($item['sort_order'] ?? ($idx + 1)),
                ];
            })
            ->filter(fn (array $item) => $item['desktop_url'] !== '' || $item['mobile_url'] !== '')
            ->sortBy('sort_order')
            ->values()
            ->all();

        Setting::set(DashboardBannerSettings::KEY, $normalized, null);

        return response()->json([
            'ok' => true,
            'banners' => $normalized,
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,gif'],
            'variant' => ['required', 'string', Rule::in(['desktop', 'mobile'])],
        ]);

        $file = $request->file('file');
        $variant = (string) $validated['variant'];
        $size = @getimagesize($file->getRealPath());
        if (! is_array($size) || ! isset($size[0], $size[1])) {
            throw ValidationException::withMessages([
                'file' => 'Não foi possível ler as dimensões da imagem.',
            ]);
        }

        $width = (int) $size[0];
        $height = (int) $size[1];
        if (! DashboardBannerSpecs::validateSize($width, $height, $variant)) {
            throw ValidationException::withMessages([
                'file' => DashboardBannerSpecs::mismatchMessage($width, $height, $variant),
            ]);
        }

        $uploaded = $this->platformStorage()->storeUploadedPublicFile($file, 'dashboard-banners');

        return response()->json([
            'ok' => true,
            'url' => $uploaded['url'],
            'variant' => $validated['variant'],
        ]);
    }

    private function normalizeStoredUrl(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return $this->platformStorage()->toStoragePath($value) ?? trim($value);
    }

    /**
     * Banners da dashboard são asset global (tenant null), inclusive com R2.
     */
    private function platformStorage(): StorageService
    {
        return new StorageService(null);
    }
}
