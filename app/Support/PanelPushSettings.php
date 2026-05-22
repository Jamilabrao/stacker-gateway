<?php

namespace App\Support;

use App\Models\BrandingSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Push do PWA do painel: leitura/gravação no branding global (tenant_id null) com fallback .env.
 */
final class PanelPushSettings
{
    public const PROVIDER_VAPID = 'vapid';

    public const PROVIDER_FCM = 'fcm';

    /** @var list<string> */
    public const TEXT_KEYS = [
        'push_provider',
        'pwa_vapid_public',
        'firebase_project_id',
        'firebase_api_key',
        'firebase_messaging_sender_id',
        'firebase_app_id',
        'firebase_web_vapid_key',
    ];

    /** @var list<string> */
    public const SECRET_KEYS = [
        'pwa_vapid_private',
        'firebase_service_account',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function globalData(): array
    {
        $row = BrandingSetting::query()->whereNull('tenant_id')->first();
        $data = is_array($row?->data) ? $row->data : [];

        return self::mergeWithEnvFallback($data);
    }

    /**
     * @param  array<string, mixed>  $stored  Raw branding JSON (may contain encrypted secrets)
     * @return array<string, mixed>
     */
    public static function mergeWithEnvFallback(array $stored): array
    {
        $provider = self::normalizeProvider((string) ($stored['push_provider'] ?? self::PROVIDER_VAPID));

        $vapidPublic = VapidEnvKeys::normalize(
            self::stringOrNull($stored['pwa_vapid_public'] ?? null) ?? env('PWA_VAPID_PUBLIC')
        );
        $vapidPrivate = self::decryptIfNeeded($stored['pwa_vapid_private'] ?? null)
            ?? VapidEnvKeys::normalize(env('PWA_VAPID_PRIVATE'));

        $firebase = [
            'project_id' => self::stringOrNull($stored['firebase_project_id'] ?? null),
            'api_key' => self::stringOrNull($stored['firebase_api_key'] ?? null),
            'messaging_sender_id' => self::stringOrNull($stored['firebase_messaging_sender_id'] ?? null),
            'app_id' => self::stringOrNull($stored['firebase_app_id'] ?? null),
            'web_vapid_key' => self::stringOrNull($stored['firebase_web_vapid_key'] ?? null),
        ];
        $serviceAccount = self::decryptIfNeeded($stored['firebase_service_account'] ?? null);

        return [
            'push_provider' => $provider,
            'pwa_vapid_public' => $vapidPublic,
            'pwa_vapid_private' => $vapidPrivate,
            'firebase_project_id' => $firebase['project_id'],
            'firebase_api_key' => $firebase['api_key'],
            'firebase_messaging_sender_id' => $firebase['messaging_sender_id'],
            'firebase_app_id' => $firebase['app_id'],
            'firebase_web_vapid_key' => $firebase['web_vapid_key'],
            'firebase_service_account' => $serviceAccount,
        ];
    }

    public static function applyToConfig(): void
    {
        $data = self::globalData();
        $provider = $data['push_provider'];

        config([
            'getfy.pwa.push_provider' => $provider,
            'getfy.pwa.vapid_public' => $data['pwa_vapid_public'],
            'getfy.pwa.vapid_private' => $data['pwa_vapid_private'],
            'getfy.pwa.firebase_project_id' => $data['firebase_project_id'],
            'getfy.pwa.firebase_api_key' => $data['firebase_api_key'],
            'getfy.pwa.firebase_messaging_sender_id' => $data['firebase_messaging_sender_id'],
            'getfy.pwa.firebase_app_id' => $data['firebase_app_id'],
            'getfy.pwa.firebase_web_vapid_key' => $data['firebase_web_vapid_key'],
            'getfy.pwa.firebase_service_account' => $data['firebase_service_account'],
        ]);
    }

    public static function isVapidConfigured(): bool
    {
        $pub = config('getfy.pwa.vapid_public');
        $priv = config('getfy.pwa.vapid_private');

        return VapidEnvKeys::normalizedPairLooksValid($pub, $priv);
    }

    public static function isFcmConfigured(): bool
    {
        return self::dataHasFcmCredentials([
            'firebase_project_id' => config('getfy.pwa.firebase_project_id'),
            'firebase_api_key' => config('getfy.pwa.firebase_api_key'),
            'firebase_messaging_sender_id' => config('getfy.pwa.firebase_messaging_sender_id'),
            'firebase_app_id' => config('getfy.pwa.firebase_app_id'),
            'firebase_web_vapid_key' => config('getfy.pwa.firebase_web_vapid_key'),
            'firebase_service_account' => config('getfy.pwa.firebase_service_account'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function dataHasFcmCredentials(array $data): bool
    {
        return self::stringOrNull($data['firebase_project_id'] ?? null) !== null
            && self::stringOrNull($data['firebase_api_key'] ?? null) !== null
            && self::stringOrNull($data['firebase_messaging_sender_id'] ?? null) !== null
            && self::stringOrNull($data['firebase_app_id'] ?? null) !== null
            && self::stringOrNull($data['firebase_web_vapid_key'] ?? null) !== null
            && self::stringOrNull($data['firebase_service_account'] ?? null) !== null;
    }

    public static function isPushEnabled(): bool
    {
        $provider = config('getfy.pwa.push_provider', self::PROVIDER_VAPID);

        return match ($provider) {
            self::PROVIDER_FCM => self::isFcmConfigured(),
            default => self::isVapidConfigured(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicClientConfig(): array
    {
        $provider = config('getfy.pwa.push_provider', self::PROVIDER_VAPID);

        if ($provider === self::PROVIDER_FCM && self::isFcmConfigured()) {
            return [
                'push_provider' => self::PROVIDER_FCM,
                'firebase' => [
                    'apiKey' => config('getfy.pwa.firebase_api_key'),
                    'authDomain' => config('getfy.pwa.firebase_project_id').'.firebaseapp.com',
                    'projectId' => config('getfy.pwa.firebase_project_id'),
                    'storageBucket' => config('getfy.pwa.firebase_project_id').'.appspot.com',
                    'messagingSenderId' => config('getfy.pwa.firebase_messaging_sender_id'),
                    'appId' => config('getfy.pwa.firebase_app_id'),
                ],
                'firebase_web_vapid_key' => config('getfy.pwa.firebase_web_vapid_key'),
            ];
        }

        return [
            'push_provider' => self::PROVIDER_VAPID,
            'vapid_public' => config('getfy.pwa.vapid_public'),
        ];
    }

    /**
     * Dados para API admin (sem segredos).
     *
     * @return array<string, mixed>
     */
    public static function adminPayload(): array
    {
        $row = BrandingSetting::query()->whereNull('tenant_id')->first();
        $stored = is_array($row?->data) ? $row->data : [];
        $effective = self::mergeWithEnvFallback($stored);

        return [
            'push_provider' => $effective['push_provider'],
            'pwa_vapid_public' => $effective['pwa_vapid_public'] ?? '',
            'pwa_vapid_private_configured' => ! empty($effective['pwa_vapid_private']),
            'pwa_vapid_from_env' => empty($stored['pwa_vapid_public']) && ! empty(env('PWA_VAPID_PUBLIC')),
            'firebase_project_id' => $effective['firebase_project_id'] ?? '',
            'firebase_api_key' => $effective['firebase_api_key'] ?? '',
            'firebase_messaging_sender_id' => $effective['firebase_messaging_sender_id'] ?? '',
            'firebase_app_id' => $effective['firebase_app_id'] ?? '',
            'firebase_web_vapid_key' => $effective['firebase_web_vapid_key'] ?? '',
            'firebase_service_account_configured' => ! empty($effective['firebase_service_account']),
            'vapid_valid' => self::isVapidConfigured(),
            'fcm_valid' => ($effective['push_provider'] ?? '') === self::PROVIDER_FCM
                && self::dataHasFcmCredentials($effective),
            'push_enabled' => self::isPushEnabled(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function saveGlobal(array $validated): void
    {
        $row = BrandingSetting::query()->firstOrCreate(
            ['tenant_id' => null],
            ['data' => []]
        );
        $data = is_array($row->data) ? $row->data : [];

        if (array_key_exists('push_provider', $validated)) {
            $data['push_provider'] = self::normalizeProvider((string) $validated['push_provider']);
        }

        foreach (self::TEXT_KEYS as $key) {
            if ($key === 'push_provider' || ! array_key_exists($key, $validated)) {
                continue;
            }
            $v = $validated[$key];
            if ($v === null || trim((string) $v) === '') {
                unset($data[$key]);
            } else {
                $data[$key] = trim((string) $v);
            }
        }

        if (array_key_exists('pwa_vapid_private', $validated) && $validated['pwa_vapid_private'] !== null && trim((string) $validated['pwa_vapid_private']) !== '') {
            $data['pwa_vapid_private'] = Crypt::encryptString(trim((string) $validated['pwa_vapid_private']));
        }

        $row->update(['data' => $data]);
        self::applyToConfig();
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generateVapidKeyPair(): array
    {
        return \Minishlink\WebPush\VAPID::createVapidKeys();
    }

    public static function storeVapidKeys(string $publicKey, string $privateKey): void
    {
        $row = BrandingSetting::query()->firstOrCreate(
            ['tenant_id' => null],
            ['data' => []]
        );
        $data = is_array($row->data) ? $row->data : [];
        $data['pwa_vapid_public'] = VapidEnvKeys::normalize($publicKey);
        $data['pwa_vapid_private'] = Crypt::encryptString(VapidEnvKeys::normalize($privateKey) ?? $privateKey);
        $row->update(['data' => $data]);
        self::applyToConfig();
    }

    public static function storeFirebaseServiceAccount(string $json): void
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || empty($decoded['project_id'])) {
            throw new \InvalidArgumentException('JSON de service account inválido.');
        }

        $row = BrandingSetting::query()->firstOrCreate(
            ['tenant_id' => null],
            ['data' => []]
        );
        $data = is_array($row->data) ? $row->data : [];
        $data['firebase_service_account'] = Crypt::encryptString($json);
        if (empty($data['firebase_project_id'])) {
            $data['firebase_project_id'] = (string) $decoded['project_id'];
        }
        $row->update(['data' => $data]);
        self::applyToConfig();
    }

    public static function normalizeProvider(string $provider): string
    {
        return $provider === self::PROVIDER_FCM ? self::PROVIDER_FCM : self::PROVIDER_VAPID;
    }

    public static function activeProvider(): string
    {
        return self::normalizeProvider((string) config('getfy.pwa.push_provider', self::PROVIDER_VAPID));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v === '' ? null : $v;
    }

    private static function decryptIfNeeded(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return trim($value) !== '' ? trim($value) : null;
        }
    }
}
