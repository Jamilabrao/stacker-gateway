<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralWalletTransaction extends Model
{
    public const TYPE_CREDIT_COMMISSION = 'credit_commission';

    public const TYPE_DEBIT_WITHDRAWAL = 'debit_withdrawal';

    public const TYPE_DEBIT_REVERSAL = 'debit_reversal';

    public const TYPE_WITHDRAWAL_REFUND = 'withdrawal_refund';

    public const TYPE_ADMIN_ADJUSTMENT = 'admin_adjustment';

    protected $fillable = [
        'user_id',
        'referral_commission_id',
        'referral_withdrawal_id',
        'type',
        'reference',
        'amount',
        'balance_after',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(ReferralCommission::class, 'referral_commission_id');
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(ReferralWithdrawal::class, 'referral_withdrawal_id');
    }
}
