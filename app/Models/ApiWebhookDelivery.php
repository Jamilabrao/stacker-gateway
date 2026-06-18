<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiWebhookDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'id',
        'tenant_id',
        'api_application_id',
        'api_key_id',
        'event',
        'event_id',
        'payload',
        'url',
        'attempt',
        'status',
        'last_status_code',
        'last_response_body',
        'next_retry_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (! is_string($model->id) || $model->id === '') {
                $model->id = (string) Str::uuid();
            }
            if (! is_string($model->event_id) || $model->event_id === '') {
                $model->event_id = (string) Str::uuid();
            }
        });
    }
}
