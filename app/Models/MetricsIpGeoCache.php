<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetricsIpGeoCache extends Model
{
    protected $table = 'metrics_ip_geo_cache';

    protected $fillable = [
        'ip_hash',
        'country',
        'region',
        'city',
        'latitude',
        'longitude',
        'isp',
        'timezone',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'resolved_at' => 'datetime',
        ];
    }
}
