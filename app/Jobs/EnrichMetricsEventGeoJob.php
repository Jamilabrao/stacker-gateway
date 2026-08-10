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
            // IP privado/reservado sem fallback: não há o que enriquecer.
            // Falha transitória do provider (IP público): não marcar geo_enriched
            // para permitir nova tentativa em job/reprocessamento futuro.
            $isPublic = (bool) filter_var(
                $this->ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if (! $isPublic) {
                $event->geo_enriched = true;
                $event->save();

                return;
            }

            throw new \RuntimeException('metrics.geo.lookup_failed_for_public_ip');
        }

        $event->fill([
            'country' => $geo['country'],
            'region' => $geo['region'],
            'city' => $geo['city'],
            'latitude' => $geo['latitude'],
            'longitude' => $geo['longitude'],
            'isp' => $geo['isp'],
            'timezone' => $geo['timezone'],
            'geo_enriched' => true,
        ])->save();

        if ($event->metrics_session_id) {
            MetricsSession::query()->whereKey($event->metrics_session_id)->where(function ($q) {
                $q->whereNull('country')->orWhere('country', '');
            })->update([
                'country' => $geo['country'],
                'region' => $geo['region'],
                'city' => $geo['city'],
            ]);
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
