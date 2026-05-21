<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proxy same-origin para o SDK público da CajuPay (evita CORS em checkout HTTP local).
 * O widget chama /checkout/cajupay/sdk-api/... e o servidor repassa para api.cajupay.com.br.
 */
class CajuPaySdkProxyController extends Controller
{
    public function __invoke(Request $request, string $path = ''): Response
    {
        if (! filter_var(config('services.cajupay.sdk_browser_proxy', true), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->corsHeaders($request));
        }

        $targetBase = rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
        $suffix = $path !== '' ? '/'.ltrim(str_replace('\\', '/', $path), '/') : '';
        $target = $targetBase.$suffix;
        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $target .= '?'.$query;
        }

        $forwardHeaders = [];
        foreach (['accept', 'content-type', 'accept-language'] as $header) {
            $v = $request->header($header);
            if (is_string($v) && $v !== '') {
                $forwardHeaders[$header] = $v;
            }
        }

        try {
            $client = Http::timeout(30)->withHeaders($forwardHeaders);
            if ($request->isMethod('GET')) {
                $upstream = $client->get($target);
            } else {
                $body = $request->getContent();
                $upstream = $client
                    ->withBody($body, $request->header('Content-Type', 'application/json'))
                    ->send(strtoupper($request->method()), $target);
            }
        } catch (\Throwable $e) {
            Log::warning('CajuPaySdkProxy: upstream failed', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Falha ao comunicar com a CajuPay.'], 502)
                ->withHeaders($this->corsHeaders($request));
        }

        $response = response($upstream->body(), $upstream->status());
        foreach ($this->corsHeaders($request) as $k => $v) {
            $response->headers->set($k, $v);
        }
        $contentType = $upstream->header('Content-Type');
        if (is_string($contentType) && $contentType !== '') {
            $response->headers->set('Content-Type', $contentType);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(Request $request): array
    {
        $allowedOrigin = $this->resolveAllowedOrigin($request->header('Origin'));
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, Authorization, X-Requested-With',
            'Access-Control-Max-Age' => '86400',
        ];
        if ($allowedOrigin !== null) {
            $headers['Access-Control-Allow-Origin'] = $allowedOrigin;
            $headers['Vary'] = 'Origin';
        }

        return $headers;
    }

    private function resolveAllowedOrigin(mixed $origin): ?string
    {
        if (! is_string($origin) || $origin === '') {
            return null;
        }

        $allowlist = [];
        $appUrl = rtrim((string) config('app.url', ''), '/');
        if ($appUrl !== '') {
            $allowlist[] = $appUrl;
        }
        $extra = env('CHECKOUT_CORS_ORIGINS', '');
        if (is_string($extra) && trim($extra) !== '') {
            foreach (explode(',', $extra) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $allowlist[] = rtrim($part, '/');
                }
            }
        }

        $normalizedOrigin = rtrim($origin, '/');
        foreach ($allowlist as $base) {
            if ($normalizedOrigin === rtrim($base, '/')) {
                return $origin;
            }
        }

        return null;
    }
}
