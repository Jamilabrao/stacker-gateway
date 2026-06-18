<?php

namespace App\Http\Middleware;

use App\Models\ApiApplication;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $credentials = $this->resolveCredentials($request);
        if ($credentials === null) {
            return response()->json(['message' => 'Missing or invalid API key.'], 401);
        }

        $resolved = $this->findApplication($credentials);
        if ($resolved === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        /** @var ApiApplication $application */
        $application = $resolved['application'];
        $apiKey = $resolved['api_key'] ?? null;

        if (! $application->is_active) {
            return response()->json(['message' => 'API application is disabled.'], 403);
        }

        if ($apiKey !== null && ! $apiKey->is_active) {
            return response()->json(['message' => 'API key is disabled.'], 403);
        }

        $ip = $request->ip();
        if ($apiKey !== null) {
            if (! $apiKey->isIpAllowed($ip)) {
                return response()->json(['message' => 'IP not allowed.'], 403);
            }
        } elseif (! $application->isIpAllowed($ip)) {
            return response()->json(['message' => 'IP not allowed.'], 403);
        }

        $request->attributes->set('api_application', $application);
        if ($apiKey !== null) {
            $request->attributes->set('api_key', $apiKey);
            $apiKey->touchLastUsed();
        }

        $request->setUserResolver(fn () => null);

        return $next($request);
    }

    /**
     * @return array{mode: 'pair', public_key: string, secret_key: string}|array{mode: 'legacy', api_key: string}|null
     */
    private function resolveCredentials(Request $request): ?array
    {
        $publicKey = trim((string) $request->header('X-Public-Key', ''));
        $secretKey = trim((string) $request->header('X-Secret-Key', ''));
        if ($publicKey !== '' && $secretKey !== '') {
            return [
                'mode' => 'pair',
                'public_key' => $publicKey,
                'secret_key' => $secretKey,
            ];
        }

        $header = $request->header('Authorization');
        if (is_string($header) && str_starts_with(strtolower($header), 'bearer ')) {
            $apiKey = trim(substr($header, 7));
            if ($apiKey !== '') {
                return ['mode' => 'legacy', 'api_key' => $apiKey];
            }
        }

        $apiKey = trim((string) $request->header('X-API-Key', ''));
        if ($apiKey !== '') {
            return ['mode' => 'legacy', 'api_key' => $apiKey];
        }

        return null;
    }

    /**
     * @return array{application: ApiApplication, api_key?: ApiKey}|null
     */
    private function findApplication(array $credentials): ?array
    {
        if (($credentials['mode'] ?? null) === 'pair') {
            $apiKey = ApiKey::query()
                ->active()
                ->where('public_key', $credentials['public_key'])
                ->first();

            if ($apiKey !== null && $apiKey->verifySecretKey($credentials['secret_key'])) {
                $app = $apiKey->apiApplication;
                if ($app !== null && $app->is_active) {
                    return ['application' => $app, 'api_key' => $apiKey];
                }
            }

            $app = ApiApplication::query()
                ->active()
                ->where('public_key', $credentials['public_key'])
                ->first();

            if ($app !== null && $app->verifySecretKey($credentials['secret_key'])) {
                return ['application' => $app];
            }

            return null;
        }

        $plainKey = (string) ($credentials['api_key'] ?? '');
        if ($plainKey === '') {
            return null;
        }

        $cacheKey = 'api_legacy_resolve:' . hash('sha256', $plainKey);
        $cachedAppId = Cache::get($cacheKey);
        if (is_int($cachedAppId) || (is_string($cachedAppId) && $cachedAppId !== '')) {
            $app = ApiApplication::active()->find((int) $cachedAppId);
            if ($app !== null && $app->verifyApiKey($plainKey)) {
                return ['application' => $app];
            }
            Cache::forget($cacheKey);
        }

        $sha = hash('sha256', $plainKey);
        $app = ApiApplication::query()
            ->active()
            ->where('legacy_api_key_sha256', $sha)
            ->first();

        if ($app !== null && $app->verifyApiKey($plainKey)) {
            Cache::put($cacheKey, $app->id, now()->addDays(7));

            return ['application' => $app];
        }

        $applications = ApiApplication::active()->get();
        foreach ($applications as $application) {
            if ($application->verifyApiKey($plainKey)) {
                if (\Illuminate\Support\Facades\Schema::hasColumn($application->getTable(), 'legacy_api_key_sha256')) {
                    $application->forceFill(['legacy_api_key_sha256' => $sha])->saveQuietly();
                }
                Cache::put($cacheKey, $application->id, now()->addDays(7));

                return ['application' => $application];
            }
        }

        return null;
    }
}
