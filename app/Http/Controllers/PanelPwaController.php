<?php

namespace App\Http\Controllers;

use App\Models\BrandingSetting;
use App\Models\PanelPushSubscription;
use App\Services\MemberAreaResolver;
use App\Support\PanelPwaIconUrls;
use App\Support\PanelPushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PanelPwaController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        $resolved = app(MemberAreaResolver::class)->resolve($request);
        if ($resolved && in_array($resolved['access_type'], ['subdomain', 'custom'], true)) {
            $request->attributes->set('member_area_product', $resolved['product']);
            $request->attributes->set('member_area_access_type', $resolved['access_type']);
            $request->attributes->set('member_area_slug', $resolved['slug']);

            return app()->call(\App\Http\Controllers\MemberAreaAppController::class.'@manifest', [
                'request' => $request,
                'slug' => $resolved['slug'],
            ]);
        }

        $appName = config('getfy.app_name', 'Getfy');
        $themeColor = config('getfy.pwa_theme_color');
        $themeColor = ($themeColor !== null && $themeColor !== '') ? (string) $themeColor : (string) config('getfy.theme_primary', '#0ea5e9');

        $brandingVersion = $this->brandingVersionForRequest($request);

        $icons = [];
        $addIconVariants = function (string $src, string $sizes) use (&$icons, $brandingVersion): void {
            $src = PanelPwaIconUrls::withVersion($src, $brandingVersion);
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'maskable'];
        };

        foreach (PanelPwaIconUrls::manifestIconSpecs() as $spec) {
            $addIconVariants($spec['src'], $spec['sizes']);
        }

        $manifest = [
            'id' => '/',
            'name' => $appName,
            'short_name' => $appName,
            'start_url' => '/login',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#18181b',
            'theme_color' => $themeColor,
            'prefer_related_applications' => false,
            'icons' => $icons,
        ];

        return response()
            ->json($manifest)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=0, must-revalidate');
    }

    private function brandingVersionForRequest(Request $request): ?string
    {
        try {
            if (! Schema::hasTable('branding_settings')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $user = $request->user();
        $tenantId = $user?->tenant_id;
        $tenantUpdatedAt = null;
        $globalUpdatedAt = null;

        if ($tenantId !== null) {
            $tenantUpdatedAt = BrandingSetting::query()
                ->where('tenant_id', $tenantId)
                ->value('updated_at');
        }
        $globalUpdatedAt = BrandingSetting::query()
            ->whereNull('tenant_id')
            ->value('updated_at');

        $best = $tenantUpdatedAt ?? $globalUpdatedAt;
        if (! $best) {
            return null;
        }

        try {
            return (string) \Illuminate\Support\Carbon::parse($best)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    public function pushSubscribe(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        if (! PanelPushSettings::isPushEnabled()) {
            return response()->json(['message' => 'Notificações push não configuradas na plataforma.'], 422);
        }

        $activeProvider = PanelPushSettings::activeProvider();

        if ($activeProvider === PanelPushSettings::PROVIDER_FCM) {
            $validated = $request->validate([
                'provider' => ['required', 'string', Rule::in([PanelPushSubscription::PROVIDER_FCM])],
                'fcm_token' => ['required', 'string', 'max:512'],
                'device_label' => ['nullable', 'string', 'max:120'],
            ]);

            $subscription = PanelPushSubscription::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => PanelPushSubscription::PROVIDER_FCM,
                ],
                [
                    'tenant_id' => $user->tenant_id,
                    'fcm_token' => $validated['fcm_token'],
                    'endpoint' => 'fcm:'.$validated['fcm_token'],
                    'keys' => null,
                    'user_agent' => $request->userAgent(),
                    'device_label' => $validated['device_label'] ?? null,
                ]
            );

            PanelPushSubscription::query()
                ->where('user_id', $user->id)
                ->where('provider', PanelPushSubscription::PROVIDER_VAPID)
                ->delete();

            return response()->json([
                'success' => true,
                'subscribed' => true,
                'provider' => PanelPushSubscription::PROVIDER_FCM,
                'subscription_id' => $subscription->id,
                'updated_at' => $subscription->updated_at?->toISOString(),
            ]);
        }

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);

        $keys = $validated['keys'];
        $keys['auth'] = $this->normalizeBase64KeyForPush((string) ($keys['auth'] ?? ''));
        $keys['p256dh'] = $this->normalizeBase64KeyForPush((string) ($keys['p256dh'] ?? ''));

        $subscription = PanelPushSubscription::updateOrCreate(
            [
                'endpoint' => $validated['endpoint'],
            ],
            [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'provider' => PanelPushSubscription::PROVIDER_VAPID,
                'fcm_token' => null,
                'keys' => $keys,
                'user_agent' => $request->userAgent(),
                'device_label' => $validated['device_label'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'subscribed' => true,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'subscription_id' => $subscription->id,
            'updated_at' => $subscription->updated_at?->toISOString(),
        ]);
    }

    private function normalizeBase64KeyForPush(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }
        if (str_contains($key, '+') || str_contains($key, '/')) {
            return strtr($key, ['+' => '-', '/' => '_']);
        }

        return $key;
    }
}
