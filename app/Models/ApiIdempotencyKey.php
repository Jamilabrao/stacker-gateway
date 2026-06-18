<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_id',
        'api_application_id',
        'api_key_id',
        'idempotency_key',
        'request_hash',
        'status',
        'response_status',
        'response_body',
        'resource_type',
        'resource_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
