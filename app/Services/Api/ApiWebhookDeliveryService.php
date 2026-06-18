<?php

namespace App\Services\Api;

use App\Jobs\DeliverApiWebhookJob;
use App\Models\ApiApplication;
use App\Models\ApiWebhookDelivery;
use App\Support\WebhookUrlValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ApiWebhookDeliveryService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(ApiApplication $app, string $event, array $payload, ?int $apiKeyId = null): ?ApiWebhookDelivery
    {
        $delivery = $this->createDeliveryRecord($app, $event, $payload, $apiKeyId);
        if ($delivery === null) {
            return null;
        }

        DeliverApiWebhookJob::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatchSync(ApiApplication $app, string $event, array $payload, ?int $apiKeyId = null): void
    {
        $delivery = $this->createDeliveryRecord($app, $event, $payload, $apiKeyId);
        if ($delivery !== null) {
            DeliverApiWebhookJob::dispatchSync($delivery->id);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createDeliveryRecord(ApiApplication $app, string $event, array $payload, ?int $apiKeyId): ?ApiWebhookDelivery
    {
        if (! $app->webhook_url || ! $app->is_active) {
            return null;
        }

        if (Schema::hasColumn($app->getTable(), 'webhook_enabled') && $app->webhook_enabled === false) {
            return null;
        }

        $subscribed = $app->webhook_events;
        if (is_array($subscribed) && count($subscribed) > 0 && ! in_array($event, $subscribed, true)) {
            return null;
        }

        try {
            WebhookUrlValidator::assertAllowed((string) $app->webhook_url);
        } catch (InvalidArgumentException $e) {
            Log::warning('Api webhook URL blocked', [
                'api_application_id' => $app->id,
                'event' => $event,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $enriched = array_merge([
            'event' => $event,
            'created_at' => now()->toIso8601String(),
        ], $payload);

        return ApiWebhookDelivery::create([
            'tenant_id' => (int) $app->tenant_id,
            'api_application_id' => $app->id,
            'api_key_id' => $apiKeyId,
            'event' => $event,
            'payload' => $enriched,
            'url' => (string) $app->webhook_url,
            'status' => ApiWebhookDelivery::STATUS_PENDING,
            'next_retry_at' => now(),
        ]);
    }

    /**
     * @return list<int>
     */
    public static function retryDelaysSeconds(): array
    {
        return [30, 120, 600, 3600, 21600, 86400];
    }

    public function scheduleRetry(ApiWebhookDelivery $delivery): void
    {
        $delays = self::retryDelaysSeconds();
        $attempt = (int) $delivery->attempt;

        if ($attempt >= count($delays)) {
            $delivery->update(['status' => ApiWebhookDelivery::STATUS_FAILED]);

            return;
        }

        $delivery->update([
            'status' => ApiWebhookDelivery::STATUS_PENDING,
            'next_retry_at' => now()->addSeconds($delays[$attempt]),
        ]);

        DeliverApiWebhookJob::dispatch($delivery->id)->delay($delays[$attempt]);
    }
}
