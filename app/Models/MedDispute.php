<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedDispute extends Model
{
    public const PARTY_PLATFORM = 'platform';

    public const PARTY_TENANT = 'tenant';

    public const STATUS_OPEN = 'open';

    public const STATUS_DEFENSE_SUBMITTED = 'defense_submitted';

    public const STATUS_RESOLVED_WON = 'resolved_won';

    public const STATUS_RESOLVED_LOST = 'resolved_lost';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'tenant_id',
        'responsible_party',
        'cajupay_dispute_id',
        'cajupay_payment_id',
        'status',
        'outcome',
        'amount_cents',
        'currency',
        'txid',
        'reason',
        'reason_code',
        'defense_text',
        'defense_dossier_path',
        'defended_at',
        'opened_at',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_note',
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function scopeTenantManaged($query)
    {
        return $query->where('responsible_party', self::PARTY_TENANT);
    }

    public function scopePlatformManaged($query)
    {
        return $query->where('responsible_party', self::PARTY_PLATFORM);
    }

    public function isTenantManaged(): bool
    {
        return $this->responsible_party === self::PARTY_TENANT;
    }

    public function isPlatformManaged(): bool
    {
        return $this->responsible_party === self::PARTY_PLATFORM;
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
