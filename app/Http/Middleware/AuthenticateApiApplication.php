<?php

namespace App\Http\Middleware;

use App\Models\ApiApplication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $credentials = $this->resolveCredentials($request);
        if ($credentials === null) {
            return response()->json(['message' => 'Missing or invalid API key.'], 401);
        }

        $application = $this->findApplication($credentials);
        if ($application === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        if (! $application->is_active) {
            return response()->json(['message' => 'API application is disabled.'], 403);
        }

        if (! $application->isIpAllowed($request->ip())) {
            return response()->json(['message' => 'IP not allowed.'], 403);
        }

        $request->attributes->set('api_application', $application);
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

    private function findApplication(array $credentials): ?ApiApplication
    {
        if (($credentials['mode'] ?? null) === 'pair') {
            $app = ApiApplication::query()
                ->active()
                ->where('public_key', $credentials['public_key'])
                ->first();

            if ($app !== null && $app->verifySecretKey($credentials['secret_key'])) {
                return $app;
            }

            return null;
        }

        $plainKey = (string) ($credentials['api_key'] ?? '');
        if ($plainKey === '') {
            return null;
        }

        $applications = ApiApplication::active()->get();
        foreach ($applications as $app) {
            if ($app->verifyApiKey($plainKey)) {
                return $app;
            }
        }

        return null;
    }
}
