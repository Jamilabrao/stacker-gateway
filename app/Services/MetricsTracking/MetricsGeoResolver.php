<?php

namespace App\Services\MetricsTracking;

use App\Models\MetricsIpGeoCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetricsGeoResolver
{
    /**
     * @return array{
     *   country:?string,region:?string,city:?string,
     *   latitude:?float,longitude:?float,isp:?string,timezone:?string
     * }|null
     */
    public function resolve(?string $ip, ?string $ipHash): ?array
    {
        if (! config('metrics_tracking.enabled', true)) {
            return null;
        }

        if (($ipHash === null || $ipHash === '') && $ip) {
            $ipHash = MetricsClientParser::hashIp($ip);
        }

        if (! $ipHash) {
            return null;
        }

        $ttlHours = max(1, (int) config('metrics_tracking.geo_cache_ttl_hours', 168));
        $cached = MetricsIpGeoCache::query()->where('ip_hash', $ipHash)->first();
        if ($cached && $cached->resolved_at && $cached->resolved_at->gt(now()->subHours($ttlHours))) {
            return [
                'country' => $cached->country,
                'region' => $cached->region,
                'city' => $cached->city,
                'latitude' => $cached->latitude !== null ? (float) $cached->latitude : null,
                'longitude' => $cached->longitude !== null ? (float) $cached->longitude : null,
                'isp' => $cached->isp,
                'timezone' => $cached->timezone,
            ];
        }

        $provider = (string) config('metrics_tracking.geo_provider', 'ip_api');
        if ($provider === 'none' || ! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $lookupIp = $ip;
        $isPublic = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (! $isPublic) {
            $fallback = trim((string) config('metrics_tracking.geo_fallback_ip', ''));
            if ($fallback === '' || ! filter_var($fallback, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
            $lookupIp = $fallback;
        }

        try {
            $geo = $this->lookupIpApi($lookupIp);
        } catch (\Throwable $e) {
            Log::warning('metrics.geo.resolve_failed', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $geo) {
            return null;
        }

        MetricsIpGeoCache::query()->updateOrCreate(
            ['ip_hash' => $ipHash],
            [
                'country' => $geo['country'],
                'region' => $geo['region'],
                'city' => $geo['city'],
                'latitude' => $geo['latitude'],
                'longitude' => $geo['longitude'],
                'isp' => $geo['isp'],
                'timezone' => $geo['timezone'],
                'resolved_at' => now(),
            ]
        );

        return $geo;
    }

    /**
     * @return array{
     *   country:?string,region:?string,city:?string,
     *   latitude:?float,longitude:?float,isp:?string,timezone:?string
     * }|null
     */
    private function lookupIpApi(string $ip): ?array
    {
        // API pública sem chave; falha silenciosa não impacta checkout.
        $url = 'http://ip-api.com/json/'.urlencode($ip).'?fields=status,country,regionName,city,lat,lon,isp,timezone';
        $response = Http::timeout(2)->acceptJson()->get($url);
        if (! $response->ok()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || ($data['status'] ?? '') !== 'success') {
            return null;
        }

        return [
            'country' => isset($data['country']) ? (string) $data['country'] : null,
            'region' => isset($data['regionName']) ? (string) $data['regionName'] : null,
            'city' => isset($data['city']) ? (string) $data['city'] : null,
            'latitude' => isset($data['lat']) ? (float) $data['lat'] : null,
            'longitude' => isset($data['lon']) ? (float) $data['lon'] : null,
            'isp' => isset($data['isp']) ? (string) $data['isp'] : null,
            'timezone' => isset($data['timezone']) ? (string) $data['timezone'] : null,
        ];
    }
}
