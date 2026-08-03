<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Métricas e Tracking interno (paralelo à UTMify / pixels)
    |--------------------------------------------------------------------------
    */
    'enabled' => filter_var(env('METRICS_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'queue' => env('METRICS_TRACKING_QUEUE', 'metrics-tracking'),

    /** Cache de geolocalização por IP (horas). */
    'geo_cache_ttl_hours' => (int) env('METRICS_GEO_CACHE_TTL_HOURS', 168),

    /** Retenção de eventos brutos (dias). 0 = sem poda automática. */
    'retention_days' => (int) env('METRICS_RETENTION_DAYS', 365),

    /** Provider de geo: ip_api (público gratuito) | none */
    'geo_provider' => env('METRICS_GEO_PROVIDER', 'ip_api'),

    /**
     * Se o IP do visitante for privado/reservado (Docker/local),
     * use este IP público só para lookup de localização.
     * Deixe vazio em produção.
     */
    'geo_fallback_ip' => env('METRICS_GEO_FALLBACK_IP'),

    /** Agregações diárias (metrics_daily_stats) para dashboards em volume. */
    'daily_stats_enabled' => filter_var(env('METRICS_DAILY_STATS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /** Preferir leitura via daily_stats em summary/timeseries (quando filtros forem compatíveis). */
    'prefer_daily_stats' => filter_var(env('METRICS_PREFER_DAILY_STATS', true), FILTER_VALIDATE_BOOLEAN),

    'cookie_session' => 'gf_msid',
    'cookie_visitor' => 'gf_vid',
    'cookie_days' => 30,
];
