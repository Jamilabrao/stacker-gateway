<?php

namespace App\Jobs;

use App\Models\MetricsEvent;
use App\Models\MetricsSession;
use App\Services\MetricsTracking\MetricsGeoResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrichMetricsEventGeoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct(
        public int $eventId,
        public string $ip,
    ) {}

    public function handle(MetricsGeoResolver $resolver): void
    {
        $event = MetricsEvent::query()->find($this->eventId);
        if (! $event || ($event->geo_enriched && $event->latitude !== null && $event->longitude !== null)) {
            return;
        }

        $geo = $resolver->resolve($this->ip, $event->ip_hash);
        if (! $geo) {
            // IP privado não resolve; marca como feito para não reenfileirar. IP público falhou: deixa retry.
            $isPublic = (bool) filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (! $isPublic) {
                $event->geo_enriched = true;
                $event->save();
            }

            return;
        }

        $event->fill([
            'country' => $geo['country'] ?: $event->country,
            'region' => $geo['region'] ?: $event->region,
            'city' => $geo['city'] ?: $event->city,
            'latitude' => $geo['latitude'] ?? $event->latitude,
            'longitude' => $geo['longitude'] ?? $event->longitude,
            'isp' => $geo['isp'] ?: $event->isp,
            'timezone' => $geo['timezone'] ?: $event->timezone,
            'geo_enriched' => true,
        ])->save();

        $payload = [
            'country' => $event->country,
            'region' => $event->region,
            'city' => $event->city,
        ];

        if ($event->metrics_session_id) {
            MetricsSession::query()->whereKey($event->metrics_session_id)->where(function ($q) {
                $q->whereNull('country')->orWhere('country', '');
            })->update($payload);

            MetricsEvent::query()
                ->where('metrics_session_id', $event->metrics_session_id)
                ->where(function ($q) {
                    $q->whereNull('country')->orWhere('country', '');
                })
                ->update(array_merge($payload, [
                    'latitude' => $event->latitude,
                    'longitude' => $event->longitude,
                ]));
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('metrics.geo_job_failed', [
            'event_id' => $this->eventId,
            'message' => $e?->getMessage(),
        ]);
    }
}
