<?php

namespace App\Models;

use App\Enums\SalesAchievementRewardStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesAchievementUnlock extends Model
{
    protected $fillable = [
        'tenant_id',
        'sales_achievement_id',
        'unlocked_at',
        'metric_value_at_unlock',
        'metric_type',
        'threshold_snapshot',
        'name_snapshot',
        'image_snapshot',
        'reward_name_snapshot',
        'reward_description_snapshot',
        'reward_image_snapshot',
        'reward_status',
        'reward_sent_at',
        'reward_carrier',
        'reward_tracking_code',
        'reward_admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'reward_sent_at' => 'datetime',
            'metric_value_at_unlock' => 'float',
            'threshold_snapshot' => 'float',
        ];
    }

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(SalesAchievement::class, 'sales_achievement_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(SalesAchievementRewardStatusHistory::class, 'sales_achievement_unlock_id');
    }

    public function rewardStatusEnum(): SalesAchievementRewardStatus
    {
        return SalesAchievementRewardStatus::tryFrom((string) $this->reward_status)
            ?? SalesAchievementRewardStatus::Pending;
    }

    public function hasReward(): bool
    {
        return filled($this->reward_name_snapshot);
    }
}
