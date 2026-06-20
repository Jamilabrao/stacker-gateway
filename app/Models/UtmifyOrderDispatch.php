<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UtmifyOrderDispatch extends Model
{
    public const DISPATCH_PENDING = 'pending';

    public const DISPATCH_SENT = 'sent';

    public const DISPATCH_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'utmify_integration_id',
        'utmify_status',
        'dispatch_status',
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
