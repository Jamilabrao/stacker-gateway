<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaConversionsApiClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int|null, body: string|null, error: string|null}
     */
    public function send(string $pixelId, string $accessToken, array $payload): array
    {
        $version = (string) config('meta_tracking.graph_api_version', 'v21.0');
        $url = sprintf('https://graph.facebook.com/%s/%s/events', $version, urlencode($pixelId));

        if (config('meta_tracking.debug')) {
            Log::debug('Meta CAPI request', [
                'pixel_id' => $pixelId,
                'payload' => $this->redactPayload($payload),
            ]);
        }

        try {
            $resp = Http::timeout((int) config('meta_tracking.http_timeout', 12))
                ->asJson()
                ->post($url, $payload + [
                    'access_token' => $accessToken,
                ]);

            $body = $resp->body();

            if (config('meta_tracking.debug')) {
                Log::debug('Meta CAPI response', [
                    'pixel_id' => $pixelId,
                    'status' => $resp->status(),
                    'body' => mb_substr($body, 0, 800),
                ]);
            }

            return [
                'ok' => $resp->successful(),
                'status' => $resp->status(),
                'body' => $body,
                'error' => $resp->successful() ? null : 'meta_api_error',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload): array
    {
        unset($payload['access_token']);

        return $payload;
    }
}
