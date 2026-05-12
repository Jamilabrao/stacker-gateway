<?php

namespace Plugins\OnlyUp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client OnlyUp: mTLS + verify_peer desligado (conforme documentação do provedor).
 */
final class OnlyUpHttp
{
    private const DEFAULT_CASHIN_BASE = 'https://api.pix.onlyup.com.br';

    private const DEFAULT_CASHOUT_BASE = 'https://accounts.onlyup.com.br';

    public static function cashInBaseUrl(array $credentials): string
    {
        $u = trim((string) ($credentials['cashin_base_url'] ?? ''));

        return $u !== '' ? rtrim($u, '/') : self::DEFAULT_CASHIN_BASE;
    }

    public static function cashOutBaseUrl(array $credentials): string
    {
        $u = trim((string) ($credentials['cashout_base_url'] ?? ''));

        return $u !== '' ? rtrim($u, '/') : self::DEFAULT_CASHOUT_BASE;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function assertCashInMtls(array $credentials): void
    {
        $crt = trim((string) ($credentials['cashin_mtls_crt_path'] ?? ''));
        $key = trim((string) ($credentials['cashin_mtls_key_path'] ?? ''));
        if ($crt === '' || $key === '' || ! is_file($crt) || ! is_file($key)) {
            throw new \InvalidArgumentException('OnlyUp: certificados mTLS cash-in ausentes ou inválidos.');
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function assertCashOutMtls(array $credentials): void
    {
        $crt = trim((string) ($credentials['cashout_mtls_crt_path'] ?? ''));
        $key = trim((string) ($credentials['cashout_mtls_key_path'] ?? ''));
        if ($crt === '' || $key === '' || ! is_file($crt) || ! is_file($key)) {
            throw new \InvalidArgumentException('OnlyUp: certificados mTLS cash-out ausentes ou inválidos.');
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function cashInClient(array $credentials): PendingRequest
    {
        self::assertCashInMtls($credentials);
        $crt = (string) $credentials['cashin_mtls_crt_path'];
        $key = (string) $credentials['cashin_mtls_key_path'];

        return Http::withOptions([
            'cert' => [$crt, $key],
            'verify' => false,
            'timeout' => 45,
            'connect_timeout' => 15,
        ])->baseUrl(self::cashInBaseUrl($credentials))
            ->acceptJson()
            ->asJson();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function cashOutClient(array $credentials): PendingRequest
    {
        self::assertCashOutMtls($credentials);
        $crt = (string) $credentials['cashout_mtls_crt_path'];
        $key = (string) $credentials['cashout_mtls_key_path'];

        return Http::withOptions([
            'cert' => [$crt, $key],
            'verify' => false,
            'timeout' => 45,
            'connect_timeout' => 15,
        ])->baseUrl(self::cashOutBaseUrl($credentials))
            ->acceptJson()
            ->asJson();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function getCashInAccessToken(array $credentials): string
    {
        $cacheKey = 'onlyup_cashin_at_'.hash('sha256', self::cashInBaseUrl($credentials).'|'.($credentials['cashin_client_id'] ?? ''));

        return (string) Cache::remember($cacheKey, now()->addSeconds(240), function () use ($credentials, $cacheKey) {
            $client = self::cashInClient($credentials);
            $response = $client->post('/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => (string) ($credentials['cashin_client_id'] ?? ''),
                'client_secret' => (string) ($credentials['cashin_client_secret'] ?? ''),
            ]);
            if (! $response->successful()) {
                Log::warning('OnlyUp cash-in token failed', ['status' => $response->status(), 'body' => $response->body()]);
                Cache::forget($cacheKey);
                throw new \RuntimeException('OnlyUp: falha ao obter token cash-in (HTTP '.$response->status().').');
            }
            $json = $response->json();
            $token = (string) ($json['access_token'] ?? '');
            if ($token === '') {
                Cache::forget($cacheKey);
                throw new \RuntimeException('OnlyUp: resposta de token cash-in inválida.');
            }

            return $token;
        });
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function getCashOutAccessToken(array $credentials): string
    {
        $cacheKey = 'onlyup_cashout_at_'.hash('sha256', self::cashOutBaseUrl($credentials).'|'.($credentials['cashout_client_id'] ?? ''));

        return (string) Cache::remember($cacheKey, now()->addSeconds(240), function () use ($credentials, $cacheKey) {
            $client = self::cashOutClient($credentials);
            $response = $client->post('/api/v2/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => (string) ($credentials['cashout_client_id'] ?? ''),
                'client_secret' => (string) ($credentials['cashout_client_secret'] ?? ''),
            ]);
            if (! $response->successful()) {
                Log::warning('OnlyUp cash-out token failed', ['status' => $response->status(), 'body' => $response->body()]);
                Cache::forget($cacheKey);
                throw new \RuntimeException('OnlyUp: falha ao obter token cash-out (HTTP '.$response->status().').');
            }
            $json = $response->json();
            $token = (string) ($json['accessToken'] ?? $json['access_token'] ?? '');
            if ($token === '') {
                Cache::forget($cacheKey);
                throw new \RuntimeException('OnlyUp: resposta de token cash-out inválida.');
            }

            return $token;
        });
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function forgetTokenCaches(array $credentials): void
    {
        $k1 = 'onlyup_cashin_at_'.hash('sha256', self::cashInBaseUrl($credentials).'|'.($credentials['cashin_client_id'] ?? ''));
        $k2 = 'onlyup_cashout_at_'.hash('sha256', self::cashOutBaseUrl($credentials).'|'.($credentials['cashout_client_id'] ?? ''));
        Cache::forget($k1);
        Cache::forget($k2);
    }
}
