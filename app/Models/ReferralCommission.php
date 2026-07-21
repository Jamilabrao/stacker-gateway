<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralCommission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'order_id',
        'referrer_user_id',
        'referred_user_id',
        'platform_fee',
        'commission_percent',
        'amount',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'platform_fee' => 'decimal:2',
            'commission_percent' => 'decimal:4',
            'amount' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
