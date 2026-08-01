<?php

namespace App\Models;

use App\Enums\SalesAchievementMetricType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesAchievement extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'metric_type',
        'threshold',
        'image',
        'sort_order',
        'is_active',
        'reward_name',
        'reward_description',
        'reward_image',
        'reward_internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'sort_order' => 'int',
            'is_active' => 'bool',
        ];
    }

    public function unlocks(): HasMany
    {
        return $this->hasMany(SalesAchievementUnlock::class, 'sales_achievement_id');
    }

    public function metricTypeEnum(): SalesAchievementMetricType
    {
        return SalesAchievementMetricType::tryFrom((string) ($this->metric_type ?: 'revenue'))
            ?? SalesAchievementMetricType::Revenue;
    }

    public function hasReward(): bool
    {
        return filled($this->reward_name);
    }
}
