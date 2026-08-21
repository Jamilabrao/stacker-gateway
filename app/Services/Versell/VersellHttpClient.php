<?php

namespace App\Services\Versell;

use App\Gateways\Versell\VersellCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente HTTP Versell com mTLS, OAuth e cache de tokens separados (Cash In / Cash Out).
 */
class VersellHttpClient
{
    public const CASH_IN_BASE_URL = 'https://api.pix.basspago.com.br';

    public const CASH_OUT_BASE_URL = 'https://pagamentos.basspago.com.br/api/v2';

    private const CASH_IN_TOKEN_SKEW_SECONDS = 40;

    private const CASH_OUT_TOKEN_SKEW_SECONDS = 90;

    private const REQUEST_TIMEOUT_SECONDS = 25;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @param  array<string, mixed>  $credentials  Blob Versell (nested ou flat)
     */
    public function getCashInAccessToken(array $credentials, bool $forceRefresh = false): string
    {
        return $this->getAccessToken(VersellCredentials::API_CASH_IN, $credentials, $forceRefresh);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getCashOutAccessToken(array $credentials, bool $forceRefresh = false): string
    {
        return $this->getAccessToken(VersellCredentials::API_CASH_OUT, $credentials, $forceRefresh);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function forgetCashInToken(array $credentials): void
    {
        $this->forgetToken(VersellCredentials::API_CASH_IN, $credentials);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function forgetCashOutToken(array $credentials): void
    {
        $this->forgetToken(VersellCredentials::API_CASH_OUT, $credentials);
    }

    /**
     * Requisição autenticada com retry único em 401 (invalida só o token da API usada).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $headers
     */
    public function request(
        string $api,
        array $credentials,
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        bool $asForm = false,
        ?array $form = null,
    ): Response {
        $api = $this->normalizeApi($api);
        $token = $this->getAccessToken($api, $credentials, false);

        $response = $this->send($api, $credentials, $method, $path, $token, $json, $headers, $asForm, $form);

        if ($response->status() !== 401) {
            return $response;
        }

        $this->forgetToken($api, $credentials);
        $fresh = $this->getAccessToken($api, $credentials, true);

        return $this->send($api, $credentials, $method, $path, $fresh, $json, $headers, $asForm, $form);
    }

    /**
     * Opções Guzzle mTLS para a API indicada (útil em testes / inspeção).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{cert: string, ssl_key: string}
     */
    public function mtlsOptions(string $api, array $credentials): array
    {
        $api = $this->normalizeApi($api);
        $block = VersellCredentials::apiBlock($credentials, $api);
        $check = VersellCredentials::assertMtlsFiles(
            $block,
            $api === VersellCredentials::API_CASH_OUT ? 'Cash Out' : 'Cash In'
        );
        if (! $check['ok']) {
            throw new RuntimeException($check['error'] ?? 'Certificados Versell inválidos.');
        }

        $cert = (string) $block['certificate_path'];
        $key = (string) $block['private_key_path'];

        // Garante que Cash In e Cash Out nunca compartilhem paths no mesmo request
        return [
            'cert' => $cert,
            'ssl_key' => $key,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function tokenCacheKey(string $api, array $credentials): string
    {
        $api = $this->normalizeApi($api);
        $block = VersellCredentials::apiBlock($credentials, $api);
        $clientId = trim((string) ($block['client_id'] ?? ''));
        $clientSecret = (string) ($block['client_secret'] ?? '');
        $cert = (string) ($block['certificate_path'] ?? '');
        $key = (string) ($block['private_key_path'] ?? '');

        return 'versell.'.$api.'.oauth.'.hash('sha256', $clientId.'|'.$clientSecret.'|'.$cert.'|'.$key);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function getAccessToken(string $api, array $credentials, bool $forceRefresh): string
    {
        $api = $this->normalizeApi($api);
        $block = VersellCredentials::apiBlock($credentials, $api);
        $label = $api === VersellCredentials::API_CASH_OUT ? 'Cash Out' : 'Cash In';

        $clientId = trim((string) ($block['client_id'] ?? ''));
        $clientSecret = (string) ($block['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException("{$label}: client_id/client_secret não configurados.");
        }

        $files = VersellCredentials::assertMtlsFiles($block, $label);
        if (! $files['ok']) {
            throw new RuntimeException($files['error'] ?? "{$label}: certificados inválidos.");
        }

        $cacheKey = $this->tokenCacheKey($api, $credentials);
        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } else {
            Cache::forget($cacheKey);
        }

        $started = microtime(true);
        try {
            $response = $api === VersellCredentials::API_CASH_OUT
                ? $this->requestCashOutToken($block)
                : $this->requestCashInToken($block);
        } catch (\Throwable $e) {
            Log::warning('Versell OAuth request failed', [
                'gateway' => 'versell',
                'api' => $api,
                'endpoint' => '/oauth/token',
                'error' => $this->sanitizeLogMessage($e->getMessage()),
            ]);
            throw new RuntimeException("{$label}: falha ao obter token OAuth.", 0, $e);
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        Log::info('Versell OAuth response', [
            'gateway' => 'versell',
            'api' => $api,
            'endpoint' => '/oauth/token',
            'status' => $response->status(),
            'elapsed_ms' => $elapsedMs,
        ]);

        if (! $response->successful()) {
            $problem = VersellProblemDetails::fromResponse(
                $response->json(),
                $response->status(),
                $response->body()
            );
            Log::warning('Versell OAuth rejected', [
                'gateway' => 'versell',
                'api' => $api,
                'endpoint' => '/oauth/token',
                'status' => $problem['status'],
                'title' => $problem['title'],
                'error' => $problem['message'],
            ]);
            throw new RuntimeException("{$label}: ".$problem['message']);
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException("{$label}: resposta OAuth sem access_token.");
        }

        $expiresIn = (int) ($response->json('expires_in') ?? ($api === VersellCredentials::API_CASH_OUT ? 3600 : 300));
        $skew = $api === VersellCredentials::API_CASH_OUT
            ? self::CASH_OUT_TOKEN_SKEW_SECONDS
            : self::CASH_IN_TOKEN_SKEW_SECONDS;
        $ttl = max(30, $expiresIn - $skew);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function requestCashInToken(array $block): Response
    {
        $mtls = [
            'cert' => (string) $block['certificate_path'],
            'ssl_key' => (string) $block['private_key_path'],
        ];

        return Http::asForm()
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withOptions(array_merge($mtls, ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS]))
            ->post(rtrim(self::CASH_IN_BASE_URL, '/').'/oauth/token', [
                'client_id' => (string) $block['client_id'],
                'client_secret' => (string) $block['client_secret'],
                'grant_type' => 'client_credentials',
            ]);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function requestCashOutToken(array $block): Response
    {
        $mtls = [
            'cert' => (string) $block['certificate_path'],
            'ssl_key' => (string) $block['private_key_path'],
        ];

        return Http::asJson()
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withOptions(array_merge($mtls, ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS]))
            ->post(rtrim(self::CASH_OUT_BASE_URL, '/').'/oauth/token', [
                'clientId' => (string) $block['client_id'],
                'clientSecret' => (string) $block['client_secret'],
                'grantType' => 'client_credentials',
            ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $form
     */
    private function send(
        string $api,
        array $credentials,
        string $method,
        string $path,
        string $token,
        ?array $json,
        array $headers,
        bool $asForm,
        ?array $form,
    ): Response {
        $pending = $this->basePending($api, $credentials)
            ->withToken($token)
            ->withHeaders($headers);

        $url = $this->url($api, $path);
        $method = strtoupper($method);

        $started = microtime(true);
        try {
            $response = match ($method) {
                'GET' => $pending->get($url),
                'DELETE' => $pending->delete($url),
                'PUT' => $asForm ? $pending->asForm()->put($url, $form ?? []) : $pending->put($url, $json ?? []),
                'PATCH' => $asForm ? $pending->asForm()->patch($url, $form ?? []) : $pending->patch($url, $json ?? []),
                default => $asForm ? $pending->asForm()->post($url, $form ?? []) : $pending->post($url, $json ?? []),
            };
        } catch (\Throwable $e) {
            Log::warning('Versell HTTP request failed', [
                'gateway' => 'versell',
                'api' => $api,
                'endpoint' => $path,
                'error' => $this->sanitizeLogMessage($e->getMessage()),
            ]);
            throw $e;
        }

        Log::info('Versell HTTP response', [
            'gateway' => 'versell',
            'api' => $api,
            'endpoint' => $path,
            'status' => $response->status(),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function basePending(string $api, array $credentials): PendingRequest
    {
        $mtls = $this->mtlsOptions($api, $credentials);

        return Http::acceptJson()
            ->asJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withOptions(array_merge($mtls, ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS]));
    }

    private function url(string $api, string $path): string
    {
        $base = $api === VersellCredentials::API_CASH_OUT
            ? self::CASH_OUT_BASE_URL
            : self::CASH_IN_BASE_URL;

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function forgetToken(string $api, array $credentials): void
    {
        Cache::forget($this->tokenCacheKey($api, $credentials));
    }

    private function normalizeApi(string $api): string
    {
        return $api === VersellCredentials::API_CASH_OUT
            ? VersellCredentials::API_CASH_OUT
            : VersellCredentials::API_CASH_IN;
    }

    private function sanitizeLogMessage(string $message): string
    {
        $message = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [redacted]', $message) ?? $message;
        if (str_contains(strtolower($message), 'begin private key')
            || str_contains(strtolower($message), 'begin certificate')
            || str_contains(strtolower($message), 'client_secret')
            || str_contains(strtolower($message), 'clientsecret')
        ) {
            return '[redacted sensitive error]';
        }

        return mb_substr($message, 0, 300);
    }
}
