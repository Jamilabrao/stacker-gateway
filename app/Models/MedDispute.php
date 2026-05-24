<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedDispute extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DEFENSE_SUBMITTED = 'defense_submitted';

    public const STATUS_RESOLVED_WON = 'resolved_won';

    public const STATUS_RESOLVED_LOST = 'resolved_lost';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'tenant_id',
        'cajupay_dispute_id',
        'cajupay_payment_id',
        'status',
        'outcome',
        'amount_cents',
        'currency',
        'txid',
        'defense_text',
        'defended_at',
        'opened_at',
        'resolved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'defended_at' => 'datetime',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function tenantOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id', 'id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_DEFENSE_SUBMITTED]);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_DEFENSE_SUBMITTED], true);
    }
}
