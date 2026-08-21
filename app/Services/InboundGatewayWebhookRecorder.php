<?php

namespace App\Services;

use App\Models\InboundGatewayWebhook;
use App\Support\GatewayWebhookTelemetry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class InboundGatewayWebhookRecorder
{
    public const REQUEST_ID_ATTRIBUTE = 'inbound_gateway_webhook_id';

    private const MAX_PAYLOAD_BYTES = 32768;

    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'password',
        'secret',
        'cvv',
        'cvc',
        'pan',
        'card_number',
        'cardnumber',
        'number',
        'bearer',
        'cookie',
        'api_key',
        'access_token',
        'refresh_token',
        'private_key',
    ];

    public function capture(Request $request): ?InboundGatewayWebhook
    {
        try {
            if (! Schema::hasTable('inbound_gateway_webhooks')) {
                return null;
            }

            $slug = $this->resolveSlug($request);
            if ($slug === '') {
                return null;
            }

            GatewayWebhookTelemetry::record($slug);

            $payload = $this->payloadFromRequest($request);
            $row = InboundGatewayWebhook::query()->create([
                'gateway_slug' => $slug,
                'http_method' => strtoupper($request->method()),
                'path' => '/'.ltrim($request->path(), '/'),
                'event' => $this->extractEvent($payload),
                'transaction_id' => $this->extractTransactionId($payload),
                'http_status' => null,
                'payload' => $payload,
                'headers' => $this->safeHeaders($request),
                'ip' => $request->ip(),
            ]);

            $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $row->id);

            return $row;
        } catch (\Throwable $e) {
            Log::debug('InboundGatewayWebhookRecorder: capture failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function markHttpStatus(Request $request, int $status): void
    {
        $this->markResponse($request, $status, null);
    }

    public function markResponse(Request $request, int $status, ?string $responseBody): void
    {
        try {
            $id = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);
            if (! is_numeric($id)) {
                return;
            }

            $update = ['http_status' => $status];
            if (Schema::hasColumn('inbound_gateway_webhooks', 'response_body')) {
                $clipped = $responseBody !== null ? trim($responseBody) : '';
                if ($clipped !== '') {
                    $update['response_body'] = mb_substr($clipped, 0, 512);
                }
            }

            InboundGatewayWebhook::query()->whereKey((int) $id)->update($update);
        } catch (\Throwable $e) {
            Log::debug('InboundGatewayWebhookRecorder: status update failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function resolveSlug(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');
        if (preg_match('#^/webhooks/gateways/([a-z0-9_-]+)#i', $path, $m)) {
            return strtolower($m[1]);
        }
        if (str_starts_with($path, '/checkout/cajupay/webhook')) {
            return 'cajupay';
        }

        $routeSlug = $request->route('slug');
        if (is_string($routeSlug) && preg_match('/^[a-z0-9_-]+$/', $routeSlug)) {
            return strtolower($routeSlug);
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payloadFromRequest(Request $request): ?array
    {
        $json = $request->json()?->all();
        if (is_array($json) && $json !== []) {
            return $this->truncateArray($this->redact($json));
        }

        $all = $request->all();
        if (is_array($all) && $all !== []) {
            return $this->truncateArray($this->redact($all));
        }

        $raw = $request->getContent();
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $this->truncateArray($this->redact($decoded));
        }

        $clip = strlen($raw) > self::MAX_PAYLOAD_BYTES
            ? substr($raw, 0, self::MAX_PAYLOAD_BYTES).'…'
            : $raw;

        return ['_raw' => $clip];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractEvent(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        foreach (['type', 'event', 'action', 'topic'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return substr(trim($value), 0, 191);
            }
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && isset($data['type']) && is_string($data['type']) && $data['type'] !== '') {
            return substr($data['type'], 0, 191);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractTransactionId(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        foreach (['payment_id', 'transaction_id', 'txid', 'charge_id', 'id'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '' && $this->looksLikeGatewayId($value)) {
                return substr($value, 0, 191);
            }
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return substr((string) $value, 0, 191);
            }
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            $id = $data['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return substr($id, 0, 191);
            }
            $charges = $data['charges'] ?? null;
            if (is_array($charges) && isset($charges[0]) && is_array($charges[0])) {
                $cid = $charges[0]['id'] ?? null;
                if (is_string($cid) && $cid !== '') {
                    return substr($cid, 0, 191);
                }
            }
        }

        return null;
    }

    private function looksLikeGatewayId(string $value): bool
    {
        return (bool) preg_match('/^(ch_|or_|pay_|pi_|pi-|cs_|evt_|tx)/i', $value)
            || strlen($value) >= 8;
    }

    /**
     * @return array<string, string>
     */
    private function safeHeaders(Request $request): array
    {
        $keep = [
            'content-type',
            'user-agent',
            'x-request-id',
            'x-correlation-id',
            'x-hub-signature',
            'x-signature',
            'x-webhook-signature',
            'x-lina-signature',
            'x-callback-signature',
        ];
        $out = [];
        foreach ($keep as $name) {
            $value = $request->headers->get($name);
            if (! is_string($value) || $value === '') {
                continue;
            }
            $out[$name] = str_contains($name, 'signature') ? '[present]' : substr($value, 0, 256);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $lk = strtolower((string) $key);
            if ($this->isSensitiveKey($lk)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redact($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function isSensitiveKey(string $lowerKey): bool
    {
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lowerKey, $fragment)) {
                if ($fragment === 'number' && ! str_contains($lowerKey, 'card')) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function truncateArray(array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) <= self::MAX_PAYLOAD_BYTES) {
            return $payload;
        }

        return [
            '_truncated' => true,
            '_preview' => substr($encoded, 0, self::MAX_PAYLOAD_BYTES).'…',
        ];
    }
}
