<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerActivityLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'action',
        'action_group',
        'source',
        'target_type',
        'target_id',
        'summary',
        'metadata',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }
}
