<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelPushSubscription extends Model
{
    public const PROVIDER_VAPID = 'vapid';

    public const PROVIDER_FCM = 'fcm';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'provider',
        'endpoint',
        'fcm_token',
        'keys',
        'user_agent',
        'device_label',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'keys' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function isFcm(): bool
    {
        return $this->provider === self::PROVIDER_FCM;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
