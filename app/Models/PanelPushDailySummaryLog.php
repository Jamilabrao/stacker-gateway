<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelPushDailySummaryLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'reference_date',
        'orders_count',
        'orders_total',
        'by_method',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'reference_date' => 'date',
            'orders_total' => 'decimal:2',
            'by_method' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }
}
