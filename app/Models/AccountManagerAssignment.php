<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountManagerAssignment extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BULK_TRANSFER = 'bulk_transfer';

    public const SOURCE_AUTOMATIC_DISTRIBUTION = 'automatic_distribution';

    public const SOURCE_MANAGER_DEACTIVATION = 'manager_deactivation';

    public const SOURCE_NEW_INFOPRODUCER = 'new_infoproducer_assignment';

    protected $fillable = [
        'merchant_user_id',
        'account_manager_id',
        'assigned_by',
        'assigned_at',
        'ended_at',
        'reason',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(AccountManager::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
