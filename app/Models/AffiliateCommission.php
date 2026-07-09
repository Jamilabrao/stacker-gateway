<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'order_id',
        'affiliate_user_id',
        'affiliate_enrollment_id',
        'producer_tenant_id',
        'producer_user_id',
        'product_id',
        'sale_origin',
        'commission_percent',
        'sale_gross',
        'commission_gross',
        'commission_fee',
        'commission_net',
        'status',
        'wallet_transaction_id',
        'affiliate_ref',
        'affiliate_link',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'sale_gross' => 'decimal:2',
            'commission_gross' => 'decimal:2',
            'commission_fee' => 'decimal:2',
            'commission_net' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ProductAffiliateEnrollment::class, 'affiliate_enrollment_id');
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'producer_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function scopeForAffiliate($query, int $affiliateUserId)
    {
        return $query->where('affiliate_user_id', $affiliateUserId);
    }
}
