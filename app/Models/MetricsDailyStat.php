<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetricsDailyStat extends Model
{
    protected $table = 'metrics_daily_stats';

    protected $fillable = [
        'tenant_id',
        'stat_date',
        'product_id',
        'dimension',
        'dimension_value',
        'unique_visitors',
        'sessions',
        'clicks',
        'checkout_views',
        'checkouts_started',
        'pix_created',
        'payments_approved',
        'payments_refused',
        'refunds',
        'gross_revenue',
        'net_revenue',
        'seconds_to_convert_sum',
        'seconds_to_convert_count',
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'gross_revenue' => 'decimal:2',
            'net_revenue' => 'decimal:2',
        ];
    }
}
