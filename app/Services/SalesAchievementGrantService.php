<?php

namespace App\Services;

use App\Enums\SalesAchievementMetricType;
use App\Enums\SalesAchievementRewardStatus;
use App\Jobs\NotifySalesAchievementEarnedJob;
use App\Models\SalesAchievement;
use App\Models\SalesAchievementUnlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SalesAchievementGrantService
{
    public function __construct(
        protected SalesAchievementsService $achievements,
    ) {}

    /**
     * Concede todas as conquistas elegíveis do tenant. Idempotente.
     *
     * @return list<SalesAchievementUnlock>
     */
    public function syncTenant(int $tenantId, string $metricType = 'revenue'): array
    {
        if ($tenantId <= 0 || ! Schema::hasTable('sales_achievement_unlocks')) {
            return [];
        }

        $type = SalesAchievementMetricType::tryFrom($metricType) ?? SalesAchievementMetricType::Revenue;
        if (! $type->isImplemented()) {
            return [];
        }

        $total = $this->achievements->getMetricValueForTenant($tenantId, $type->value);
        $catalog = SalesAchievement::query()
            ->where('is_active', true)
            ->where('metric_type', $type->value)
            ->orderBy('threshold')
            ->get();

        if ($catalog->isEmpty()) {
            return [];
        }

        $existing = SalesAchievementUnlock::query()
            ->where('tenant_id', $tenantId)
            ->pluck('sales_achievement_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $existingMap = array_fill_keys($existing, true);

        $granted = [];
        foreach ($catalog as $achievement) {
            if (isset($existingMap[(int) $achievement->id])) {
                continue;
            }
            if ($total < (float) $achievement->threshold) {
                continue;
            }

            [$unlock, $created] = $this->grantOne($tenantId, $achievement, $total);
            if ($unlock && $created) {
                $granted[] = $unlock;
            }
            if ($unlock) {
                $existingMap[(int) $achievement->id] = true;
            }
        }

        return $granted;
    }

    /**
     * @return array{0: ?SalesAchievementUnlock, 1: bool} [unlock, wasCreated]
     */
    public function grantOne(int $tenantId, SalesAchievement $achievement, float $metricValue): array
    {
        $hasReward = $achievement->hasReward();
        $initialStatus = $hasReward
            ? SalesAchievementRewardStatus::Pending->value
            : SalesAchievementRewardStatus::NotApplicable->value;

        $created = false;
        $unlock = null;

        try {
            DB::transaction(function () use ($tenantId, $achievement, $metricValue, $hasReward, $initialStatus, &$unlock, &$created) {
                $existing = SalesAchievementUnlock::query()
                    ->where('tenant_id', $tenantId)
                    ->where('sales_achievement_id', $achievement->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $unlock = $existing;

                    return;
                }

                $unlock = SalesAchievementUnlock::query()->create([
                    'tenant_id' => $tenantId,
                    'sales_achievement_id' => $achievement->id,
                    'unlocked_at' => now(),
                    'metric_value_at_unlock' => round($metricValue, 2),
                    'metric_type' => $achievement->metric_type ?: 'revenue',
                    'threshold_snapshot' => (float) $achievement->threshold,
                    'name_snapshot' => (string) $achievement->name,
                    'image_snapshot' => $achievement->image,
                    'reward_name_snapshot' => $hasReward ? $achievement->reward_name : null,
                    'reward_description_snapshot' => $hasReward ? $achievement->reward_description : null,
                    'reward_image_snapshot' => $hasReward ? $achievement->reward_image : null,
                    'reward_status' => $initialStatus,
                ]);
                $created = true;
            });
        } catch (\Throwable $e) {
            Log::info('SalesAchievementGrantService: unlock já existia ou falhou', [
                'tenant_id' => $tenantId,
                'achievement_id' => $achievement->id,
                'error' => $e->getMessage(),
            ]);

            return [
                SalesAchievementUnlock::query()
                    ->where('tenant_id', $tenantId)
                    ->where('sales_achievement_id', $achievement->id)
                    ->first(),
                false,
            ];
        }

        if ($created && $unlock) {
            PlatformAuditService::log('achievement.earned', [
                'tenant_id' => $tenantId,
                'sales_achievement_id' => $achievement->id,
                'unlock_id' => $unlock->id,
                'metric_value' => $metricValue,
                'threshold' => (float) $achievement->threshold,
                'reward_name' => $unlock->reward_name_snapshot,
                'reward_status' => $unlock->reward_status,
            ]);

            try {
                NotifySalesAchievementEarnedJob::dispatch($unlock->id);
            } catch (\Throwable $e) {
                Log::warning('SalesAchievementGrantService: falha ao enfileirar notificação', [
                    'unlock_id' => $unlock->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$unlock, $created];
    }

    public function reconcileAll(string $metricType = 'revenue'): int
    {
        if (! Schema::hasTable('sales_achievement_unlocks')) {
            return 0;
        }

        $count = 0;
        User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where(function ($q) {
                $q->where('account_status', 'approved')->orWhereNull('account_status');
            })
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($metricType, &$count) {
                foreach ($users as $user) {
                    $tenantId = (int) ($user->tenant_id ?: $user->id);
                    $granted = $this->syncTenant($tenantId, $metricType);
                    $count += count($granted);
                }
            });

        PlatformAuditService::log('achievement.progress_recalculated', [
            'metric_type' => $metricType,
            'granted_count' => $count,
        ]);

        return $count;
    }
}
