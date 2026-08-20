<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundGatewayWebhook extends Model
{
    protected $fillable = [
        'gateway_slug',
        'http_method',
        'path',
        'event',
        'transaction_id',
        'http_status',
        'payload',
        'headers',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'http_status' => 'integer',
        ];
    }
}
