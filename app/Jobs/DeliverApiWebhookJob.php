<?php

namespace App\Jobs;

use App\Models\ApiWebhookDelivery;
use App\Support\WebhookUrlValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DeliverApiWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $deliveryId,
    ) {
        $this->onQueue((string) config('queue.webhooks_outbound_queue', 'webhooks-outbound'));
    }

    public function handle(\App\Services\Api\ApiWebhookDeliveryService $deliveryService): void
    {
        $delivery = ApiWebhookDelivery::query()->find($this->deliveryId);
        if (! $delivery || $delivery->status === ApiWebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        $app = \App\Models\ApiApplication::query()->find($delivery->api_application_id);
        if (! $app || ! $app->webhook_url || ! $app->is_active) {
            $delivery->update(['status' => ApiWebhookDelivery::STATUS_FAILED]);

            return;
        }

        try {
            WebhookUrlValidator::assertAllowed((string) $delivery->url);
        } catch (InvalidArgumentException $e) {
            Log::warning('DeliverApiWebhookJob URL blocked', ['delivery_id' => $delivery->id]);
            $delivery->update(['status' => ApiWebhookDelivery::STATUS_FAILED]);

            return;
        }

        $payload = $delivery->payload;
        $payload['event_id'] = $delivery->event_id;

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json'];

        if ($app->webhook_secret) {
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', $body, $app->webhook_secret);
            $headers['X-Webhook-Id'] = $delivery->event_id;
            $headers['X-Webhook-Timestamp'] = (string) now()->timestamp;
        }

        $attempt = (int) $delivery->attempt + 1;

        try {
            $response = Http::timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($delivery->url);

            $delivery->update([
                'attempt' => $attempt,
                'last_status_code' => $response->status(),
                'last_response_body' => Str::limit($response->body(), 2000),
            ]);

            if ($response->successful()) {
                $delivery->update([
                    'status' => ApiWebhookDelivery::STATUS_DELIVERED,
                    'delivered_at' => now(),
                    'next_retry_at' => null,
                ]);

                return;
            }

            Log::warning('DeliverApiWebhookJob non-2xx', [
                'delivery_id' => $delivery->id,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            $delivery->update([
                'attempt' => $attempt,
                'last_response_body' => Str::limit($e->getMessage(), 2000),
            ]);
            Log::warning('DeliverApiWebhookJob transport error', [
                'delivery_id' => $delivery->id,
                'message' => $e->getMessage(),
            ]);
        }

        $deliveryService->scheduleRetry($delivery->fresh());
    }
}
