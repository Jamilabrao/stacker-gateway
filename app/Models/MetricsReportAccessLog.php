<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetricsReportAccessLog extends Model
{
    protected $table = 'metrics_report_access_logs';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'report',
        'filters',
        'ip_masked',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }
}
