<?php

namespace App\Jobs\Meta;

use App\Models\MetaTrackingEvent;
use App\Models\Order;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMetaTrackingEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public int $metaTrackingEventId)
    {
        $this->tries = (int) config('meta_tracking.retry.tries', 8);
        $this->timeout = (int) config('meta_tracking.retry.timeout', 60);
        $this->onQueue((string) config('meta_tracking.queue', 'meta-tracking'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('meta_tracking.retry.backoff', [30, 60, 120, 300, 600, 1200, 1800]);

        return is_array($backoff) ? array_map('intval', $backoff) : [30, 60, 120];
    }

    public function handle(MetaTrackingService $service): void
    {
        $record = MetaTrackingEvent::query()->find($this->metaTrackingEventId);
        if (! $record) {
            return;
        }

        if ($record->status === MetaTrackingEvent::STATUS_SENT) {
            return;
        }

        $record->increment('attempts');

        $result = $service->sendTrackingEventRecord($record);

        if ($result['ok']) {
            $record->update([
                'status' => MetaTrackingEvent::STATUS_SENT,
                'sent_at' => now(),
                'response_body' => isset($result['body']) ? mb_substr((string) $result['body'], 0, 2000) : null,
                'last_error' => null,
            ]);

            if ($record->event_name === 'Purchase' && $record->context_type === MetaTrackingEvent::CONTEXT_ORDER) {
                $order = Order::query()->find($record->context_id);
                if ($order) {
                    $service->markOrderPurchaseSent($order);
                }
            }

            Log::info('Meta CAPI event sent', [
                'meta_tracking_event_id' => $record->id,
                'event_name' => $record->event_name,
                'event_id' => $record->event_id,
                'pixel_id' => $record->pixel_id,
            ]);

            return;
        }

        $summary = sprintf(
            'pixel %s: %s (HTTP %s)',
            $record->pixel_id,
            $result['error'] ?? 'meta_api_error',
            $result['status'] ?? 'n/a'
        );

        $record->update([
            'last_error' => mb_substr($summary, 0, 500),
            'response_body' => isset($result['body']) ? mb_substr((string) $result['body'], 0, 2000) : null,
        ]);

        if ($record->event_name === 'Purchase' && $record->context_type === MetaTrackingEvent::CONTEXT_ORDER) {
            $order = Order::query()->find($record->context_id);
            if ($order) {
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $meta['meta_capi_last_error'] = mb_substr($summary, 0, 500);
                $meta['meta_capi_last_attempt_at'] = now()->toIso8601String();
                $order->update(['metadata' => $meta]);
            }
        }

        Log::warning('Meta CAPI event send failed', [
            'meta_tracking_event_id' => $record->id,
            'event_name' => $record->event_name,
            'event_id' => $record->event_id,
            'pixel_id' => $record->pixel_id,
            'attempt' => $record->attempts,
            'error' => $summary,
        ]);

        throw new \RuntimeException('Meta CAPI send failed: '.$summary);
    }

    public function failed(?\Throwable $exception): void
    {
        $record = MetaTrackingEvent::query()->find($this->metaTrackingEventId);
        if (! $record) {
            return;
        }

        if ($record->status === MetaTrackingEvent::STATUS_SENT) {
            return;
        }

        $record->update([
            'status' => MetaTrackingEvent::STATUS_FAILED,
            'last_error' => $exception !== null ? mb_substr($exception->getMessage(), 0, 500) : 'failed',
        ]);

        if ($record->event_name === 'Purchase' && $record->context_type === MetaTrackingEvent::CONTEXT_ORDER) {
            $order = Order::query()->find($record->context_id);
            if (! $order) {
                return;
            }

            $meta = is_array($order->metadata) ? $order->metadata : [];
            if (! empty($meta['meta_capi_sent_purchase'])) {
                return;
            }

            $meta['meta_capi_failed'] = true;
            $meta['meta_capi_failed_at'] = now()->toIso8601String();
            if ($exception !== null) {
                $meta['meta_capi_last_error'] = mb_substr($exception->getMessage(), 0, 500);
            }
            $order->update(['metadata' => $meta]);
        }

        Log::error('Meta CAPI event failed after retries', [
            'meta_tracking_event_id' => $record->id,
            'event_name' => $record->event_name,
            'event_id' => $record->event_id,
            'message' => $exception?->getMessage(),
        ]);
    }
}
