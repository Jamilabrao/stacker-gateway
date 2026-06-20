<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaTrackingEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const CONTEXT_SESSION = 'session';

    public const CONTEXT_ORDER = 'order';

    protected $fillable = [
        'tenant_id',
        'event_name',
        'event_id',
        'context_type',
        'context_id',
        'pixel_id',
        'status',
        'attempts',
        'last_error',
        'response_body',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
