<?php

namespace App\Services\Api;

use App\Models\ApiApplication;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiIdempotencyService
{
    public function usesStrictIdempotency(ApiApplication $app, ?ApiKey $apiKey): bool
    {
        if ($apiKey !== null) {
            return (bool) $apiKey->strict_idempotency;
        }

        return (bool) ($app->strict_idempotency ?? false);
    }

    public function isLegacyMode(ApiApplication $app, ?ApiKey $apiKey): bool
    {
        if ($apiKey !== null) {
            return false;
        }

        return (bool) ($app->is_legacy ?? true);
    }

    /**
     * @param  callable(): JsonResponse  $handler
     */
    public function handle(
        ApiApplication $app,
        ?ApiKey $apiKey,
        ?string $idempotencyKey,
        string $requestBody,
        callable $handler
    ): JsonResponse {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            if ($this->usesStrictIdempotency($app, $apiKey)) {
                return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
            }

            return $handler();
        }

        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) > 128) {
            return response()->json(['message' => 'Idempotency-Key too long.'], 422);
        }

        if ($this->isLegacyMode($app, $apiKey) && ! $this->usesStrictIdempotency($app, $apiKey)) {
            return $this->handleLegacyCache($app->id, $idempotencyKey, $handler);
        }

        return $this->handleDatabase($app, $apiKey, $idempotencyKey, $requestBody, $handler);
    }

    /**
     * @param  callable(): JsonResponse  $handler
     */
    private function handleLegacyCache(int $appId, string $key, callable $handler): JsonResponse
    {
        $cacheKey = 'idempotency:api:' . $appId . ':' . $key;
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json($cached['body'], $cached['status']);
        }

        $response = $handler();
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true),
            ], now()->addHours(24));
        }

        return $response;
    }

    /**
     * @param  callable(): JsonResponse  $handler
     */
    private function handleDatabase(
        ApiApplication $app,
        ?ApiKey $apiKey,
        string $idempotencyKey,
        string $requestBody,
        callable $handler
    ): JsonResponse {
        $requestHash = hash('sha256', $requestBody);
        $tenantId = (int) $app->tenant_id;

        $existing = ApiIdempotencyKey::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return response()->json([
                    'message' => 'Idempotency-Key was already used with a different request body.',
                ], 409);
            }

            if ($existing->status === ApiIdempotencyKey::STATUS_IN_PROGRESS) {
                return response()->json([
                    'message' => 'Request with this Idempotency-Key is still being processed.',
                ], 409)->header('Retry-After', '5');
            }

            if (is_array($existing->response_body) && $existing->response_status !== null) {
                return response()->json($existing->response_body, $existing->response_status);
            }
        }

        try {
            DB::table('api_idempotency_keys')->insert([
                'tenant_id' => $tenantId,
                'api_application_id' => $app->id,
                'api_key_id' => $apiKey?->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => ApiIdempotencyKey::STATUS_IN_PROGRESS,
                'expires_at' => now()->addHours(24),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (! Str::contains($e->getMessage(), ['unique', 'Duplicate', 'UNIQUE'])) {
                throw $e;
            }

            $race = ApiIdempotencyKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($race !== null && $race->request_hash !== $requestHash) {
                return response()->json(['message' => 'Idempotency-Key conflict.'], 409);
            }

            if ($race !== null && is_array($race->response_body) && $race->response_status !== null) {
                return response()->json($race->response_body, $race->response_status);
            }

            return response()->json(['message' => 'Request in progress.'], 409)->header('Retry-After', '5');
        }

        $response = $handler();
        $status = $response->getStatusCode();
        $body = json_decode($response->getContent(), true);

        if ($status >= 200 && $status < 300) {
            ApiIdempotencyKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->update([
                    'status' => ApiIdempotencyKey::STATUS_COMPLETED,
                    'response_status' => $status,
                    'response_body' => json_encode($body),
                    'updated_at' => now(),
                ]);
        } else {
            ApiIdempotencyKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->delete();
        }

        return $response;
    }
}
