<?php

namespace App\Services\Cnpj;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta CNPJ na BrasilAPI. Falha nunca lança: o cadastro segue sem a Receita.
 */
class BrasilApiCnpjClient
{
    public const STATUS_OK = 'ok';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_UNAVAILABLE = 'unavailable';

    private const CACHE_TTL_SUCCESS = 86400;

    private const CACHE_TTL_FAILURE = 60;

    /**
     * @return array{status: string, error: ?string, payload: ?array<string, mixed>}
     */
    public function lookup(string $cnpjDigits, bool $fresh = false): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpjDigits) ?? '';
        if (strlen($cnpj) !== 14) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'error' => 'cnpj_invalido',
                'payload' => null,
            ];
        }

        $cacheKey = 'brasilapi:cnpj:'.$cnpj;
        if ($fresh) {
            Cache::forget($cacheKey);
        } elseif (Cache::has($cacheKey)) {
            /** @var array{status: string, error: ?string, payload: ?array<string, mixed>} $cached */
            $cached = Cache::get($cacheKey);

            return $cached;
        }

        $result = $this->request($cnpj);
        $ttl = $result['status'] === self::STATUS_OK ? self::CACHE_TTL_SUCCESS : self::CACHE_TTL_FAILURE;
        Cache::put($cacheKey, $result, $ttl);

        return $result;
    }

    /**
     * @return array{status: string, error: ?string, payload: ?array<string, mixed>}
     */
    private function request(string $cnpj): array
    {
        $base = rtrim((string) config('services.brasilapi.cnpj_url', 'https://brasilapi.com.br/api/cnpj/v1'), '/');
        $timeout = (float) config('services.brasilapi.timeout', 3);
        $connectTimeout = min(2.0, $timeout);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->acceptJson()
                ->get($base.'/'.$cnpj);
        } catch (ConnectionException $e) {
            Log::notice('brasilapi.cnpj.unavailable', [
                'cnpj' => $cnpj,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => self::STATUS_UNAVAILABLE,
                'error' => 'timeout',
                'payload' => null,
            ];
        } catch (\Throwable $e) {
            Log::notice('brasilapi.cnpj.unavailable', [
                'cnpj' => $cnpj,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => self::STATUS_UNAVAILABLE,
                'error' => 'exception',
                'payload' => null,
            ];
        }

        if ($response->status() === 404) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'error' => 'not_found',
                'payload' => null,
            ];
        }

        if (! $response->successful()) {
            Log::notice('brasilapi.cnpj.http_error', [
                'cnpj' => $cnpj,
                'status' => $response->status(),
            ]);

            return [
                'status' => self::STATUS_UNAVAILABLE,
                'error' => 'http_'.$response->status(),
                'payload' => null,
            ];
        }

        $json = $response->json();
        if (! is_array($json) || trim((string) ($json['razao_social'] ?? $json['cnpj'] ?? '')) === '') {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'error' => 'invalid_payload',
                'payload' => null,
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'error' => null,
            'payload' => $json,
        ];
    }
}
