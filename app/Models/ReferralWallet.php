<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralWallet extends Model
{
    protected $fillable = [
        'user_id',
        'available_balance',
        'pending_balance',
        'lifetime_earned',
        'lifetime_withdrawn',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'available_balance' => 'decimal:2',
            'pending_balance' => 'decimal:2',
            'lifetime_earned' => 'decimal:2',
            'lifetime_withdrawn' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ReferralWalletTransaction::class, 'user_id', 'user_id');
    }

    public static function forUser(int $userId): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'lifetime_earned' => 0,
                'lifetime_withdrawn' => 0,
                'currency' => 'BRL',
            ]
        );
    }
}
