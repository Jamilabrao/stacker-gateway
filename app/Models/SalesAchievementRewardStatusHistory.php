<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesAchievementRewardStatusHistory extends Model
{
    protected $table = 'sales_achievement_reward_status_history';

    protected $fillable = [
        'sales_achievement_unlock_id',
        'from_status',
        'to_status',
        'changed_by',
        'note',
        'tracking_code',
        'carrier',
    ];

    public function unlock(): BelongsTo
    {
        return $this->belongsTo(SalesAchievementUnlock::class, 'sales_achievement_unlock_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
