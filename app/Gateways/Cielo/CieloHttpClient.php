<?php

namespace App\Gateways\Cielo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HTTP da API E-commerce Cielo (transacional vs consulta) e Silent Order Post.
 */
final class CieloHttpClient
{
    public const TRANSACTIONAL_PRODUCTION = 'https://api.cieloecommerce.cielo.com.br';

    public const TRANSACTIONAL_SANDBOX = 'https://apisandbox.cieloecommerce.cielo.com.br';

    public const QUERY_PRODUCTION = 'https://apiquery.cieloecommerce.cielo.com.br';

    public const QUERY_SANDBOX = 'https://apiquerysandbox.cieloecommerce.cielo.com.br';

    public const SOP_AUTH_PRODUCTION = 'https://auth.braspag.com.br/oauth2/token';

    public const SOP_AUTH_SANDBOX = 'https://authsandbox.braspag.com.br/oauth2/token';

    public const SOP_TOKEN_PRODUCTION = 'https://transaction.pagador.com.br/post/api/public/v2/accesstoken';

    public const SOP_TOKEN_SANDBOX = 'https://transactionsandbox.pagador.com.br/post/api/public/v2/accesstoken';

    /** Credenciais SOP de sandbox documentadas pela Cielo (públicas). */
    public const SOP_SANDBOX_CLIENT_ID = '6631016d-72e6-4db1-a2d2-9cbe61464925';

    public const SOP_SANDBOX_CLIENT_SECRET = 'flY1vN/dDx/5A9g/shJoOlTEiZfb9bZrig/WtJojqMM=';

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function isSandbox(array $credentials): bool
    {
        return isset($credentials['sandbox']) && filter_var($credentials['sandbox'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function merchantId(array $credentials): string
    {
        return trim((string) ($credentials['merchant_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function merchantKey(array $credentials): string
    {
        return trim((string) ($credentials['merchant_key'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function transactionalBase(array $credentials): string
    {
        return rtrim(self::isSandbox($credentials) ? self::TRANSACTIONAL_SANDBOX : self::TRANSACTIONAL_PRODUCTION, '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function queryBase(array $credentials): string
    {
        return rtrim(self::isSandbox($credentials) ? self::QUERY_SANDBOX : self::QUERY_PRODUCTION, '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function salesRequest(array $credentials, int $timeout = 25): PendingRequest
    {
        $merchantId = self::merchantId($credentials);
        $merchantKey = self::merchantKey($credentials);
        if ($merchantId === '' || $merchantKey === '') {
            throw new \RuntimeException('Cielo: MerchantId e MerchantKey são obrigatórios.');
        }

        $timeout = min(60, max(5, $timeout));

        return Http::acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withHeaders([
                'MerchantId' => $merchantId,
                'MerchantKey' => $merchantKey,
                'RequestId' => (string) Str::uuid(),
                'User-Agent' => config('app.name', 'Checkout').'/Cielo',
            ])
            ->withOptions(['connect_timeout' => min(15, max(2, (int) ceil($timeout / 4)))]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $body
     */
    public static function send(
        array $credentials,
        string $method,
        string $url,
        ?array $body = null,
        int $timeout = 25
    ): Response {
        $pending = self::salesRequest($credentials, $timeout);
        $method = strtoupper($method);

        return match ($method) {
            'GET' => $pending->get($url),
            'PUT' => $body === null ? $pending->put($url) : $pending->put($url, $body),
            default => $pending->post($url, $body ?? []),
        };
    }

    /**
     * AccessToken do Silent Order Post para o script no browser.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{AccessToken: string, environment: string, ExpiresIn?: string}
     */
    public static function createSopAccessToken(array $credentials): array
    {
        $sandbox = self::isSandbox($credentials);
        $clientId = trim((string) ($credentials['sop_client_id'] ?? ''));
        $clientSecret = trim((string) ($credentials['sop_client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            if (! $sandbox) {
                throw new \RuntimeException('Cielo: credenciais do Silent Order Post (ClientId e ClientSecret) não configuradas.');
            }
            $clientId = self::SOP_SANDBOX_CLIENT_ID;
            $clientSecret = self::SOP_SANDBOX_CLIENT_SECRET;
        }

        $basic = base64_encode($clientId.':'.$clientSecret);
        $authUrl = $sandbox ? self::SOP_AUTH_SANDBOX : self::SOP_AUTH_PRODUCTION;
        $oauth = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->withHeaders([
                'Authorization' => 'Basic '.$basic,
            ])
            ->post($authUrl, ['grant_type' => 'client_credentials']);

        if (! $oauth->successful()) {
            Log::warning('Cielo SOP OAuth failed', ['status' => $oauth->status()]);
            throw new \RuntimeException('Cielo: não foi possível autenticar o Silent Order Post.');
        }

        $accessToken = trim((string) $oauth->json('access_token', ''));
        if ($accessToken === '') {
            throw new \RuntimeException('Cielo: OAuth do Silent Order Post não retornou access_token.');
        }

        $merchantId = self::merchantId($credentials);
        if ($merchantId === '') {
            throw new \RuntimeException('Cielo: MerchantId é obrigatório para o Silent Order Post.');
        }

        $sopUrl = $sandbox ? self::SOP_TOKEN_SANDBOX : self::SOP_TOKEN_PRODUCTION;
        $sop = Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->withToken($accessToken)
            ->withHeaders(['MerchantId' => $merchantId])
            ->post($sopUrl);

        if (! $sop->successful()) {
            Log::warning('Cielo SOP AccessToken failed', ['status' => $sop->status()]);
            throw new \RuntimeException('Cielo: não foi possível gerar o AccessToken do Silent Order Post.');
        }

        $token = trim((string) $sop->json('AccessToken', ''));
        if ($token === '') {
            throw new \RuntimeException('Cielo: Silent Order Post não retornou AccessToken.');
        }

        return [
            'AccessToken' => $token,
            'environment' => $sandbox ? 'sandbox' : 'production',
            'ExpiresIn' => (string) ($sop->json('ExpiresIn') ?? ''),
        ];
    }
}
