<?php

namespace App\Services;

use App\Enums\SalesAchievementMetricType;
use App\Models\Order;
use App\Models\SalesAchievement;
use App\Models\SalesAchievementUnlock;
use Illuminate\Support\Facades\Schema;

class SalesAchievementsService
{
    public function getValidSalesTotal(?int $tenantId): float
    {
        return (float) Order::forTenant($tenantId)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('approved_manually', false)
                    ->orWhereNull('approved_manually');
            })
            ->whereNotNull('gateway')
            ->where('gateway', '!=', 'manual')
            ->sum('amount');
    }

    /**
     * Query base de vendas válidas agrupadas por tenant (mesma regra de getValidSalesTotal).
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Order>
     */
    public function validSalesTotalsQuery()
    {
        return Order::query()
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('approved_manually', false)
                    ->orWhereNull('approved_manually');
            })
            ->whereNotNull('gateway')
            ->where('gateway', '!=', 'manual')
            ->selectRaw('tenant_id, SUM(amount) as total')
            ->groupBy('tenant_id');
    }

    /**
     * @return array<int, float> tenant_id => total
     */
    public function getValidSalesTotalsGrouped(): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        return $this->mapSalesTotalsRows($this->validSalesTotalsQuery()->get());
    }

    /**
     * @param  iterable<int|string>  $tenantIds
     * @return array<int, float> tenant_id => total
     */
    public function getValidSalesTotalsForTenants(iterable $tenantIds): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $ids = collect($tenantIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return $this->mapSalesTotalsRows(
            $this->validSalesTotalsQuery()->whereIn('tenant_id', $ids)->get()
        );
    }

    /**
     * Valor atual da métrica oficial. Na v1 somente revenue é implementado.
     */
    public function getMetricValueForTenant(?int $tenantId, string $metricType = 'revenue'): float
    {
        $type = SalesAchievementMetricType::tryFrom($metricType) ?? SalesAchievementMetricType::Revenue;
        if (! $type->isImplemented()) {
            return 0.0;
        }

        return $this->getValidSalesTotal($tenantId);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>|\Illuminate\Database\Eloquent\Collection<int, Order>  $rows
     * @return array<int, float>
     */
    private function mapSalesTotalsRows($rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $tid = (int) ($row->tenant_id ?? 0);
            if ($tid > 0) {
                $out[$tid] = round((float) $row->total, 2);
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   total_valid_sales: float,
     *   current_value: float,
     *   metric_type: string,
     *   current_achievement: array|null,
     *   next_achievement: array|null,
     *   progress_percent: float,
     *   remaining: float,
     *   all_completed: bool,
     *   achievements: array,
     *   unlocks: array
     * }
     */
    public function getProgressForTenant(?int $tenantId, string $metricType = 'revenue'): array
    {
        $total = $this->getMetricValueForTenant($tenantId, $metricType);
        $achievements = $this->getAchievementsCatalog($metricType, includeInternalNotes: false);

        $current = null;
        $next = null;
        $unlockedIds = $this->unlockedAchievementIdsForTenant((int) ($tenantId ?? 0));

        $result = [];
        foreach ($achievements as $a) {
            $unlocked = $total >= $a['threshold'] || isset($unlockedIds[(int) ($a['id'] ?? 0)]);
            $row = $a + ['unlocked' => $unlocked];
            $result[] = $row;

            if ($unlocked) {
                $current = $a;
            } elseif ($next === null) {
                $next = $a;
            }
        }

        $progressPercent = 0.0;
        $remaining = 0.0;
        $allCompleted = $next === null && $current !== null;

        if ($next !== null) {
            $target = (float) $next['threshold'];
            $progressPercent = $target > 0 ? min(100, max(0, ($total / $target) * 100)) : 0;
            $remaining = max(0, round($target - $total, 2));
        } elseif ($allCompleted) {
            $progressPercent = 100;
            $remaining = 0;
        }

        return [
            'total_valid_sales' => $total,
            'current_value' => $total,
            'metric_type' => $metricType,
            'current_achievement' => $current,
            'next_achievement' => $next,
            'progress_percent' => round($progressPercent, 1),
            'remaining' => $remaining,
            'all_completed' => $allCompleted,
            'achievements' => $result,
            'unlocks' => $this->unlocksPayloadForTenant((int) ($tenantId ?? 0), forAdmin: false),
        ];
    }

    public function getAchievementBySlug(string $slug): ?array
    {
        $achievements = $this->getAchievementsCatalog();
        foreach ($achievements as $a) {
            if (($a['slug'] ?? '') === $slug) {
                return $a;
            }
        }

        return null;
    }

    public function getValidSlugs(): array
    {
        $achievements = $this->getAchievementsCatalog();

        return array_column($achievements, 'slug');
    }

    /**
     * @return array<int, true> achievement_id => true
     */
    public function unlockedAchievementIdsForTenant(int $tenantId): array
    {
        if ($tenantId <= 0 || ! Schema::hasTable('sales_achievement_unlocks')) {
            return [];
        }

        return SalesAchievementUnlock::query()
            ->where('tenant_id', $tenantId)
            ->pluck('sales_achievement_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function unlocksPayloadForTenant(int $tenantId, bool $forAdmin = false): array
    {
        if ($tenantId <= 0 || ! Schema::hasTable('sales_achievement_unlocks')) {
            return [];
        }

        $storage = app(StorageService::class);

        return SalesAchievementUnlock::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('unlocked_at')
            ->get()
            ->map(function (SalesAchievementUnlock $u) use ($storage, $forAdmin) {
                $payload = [
                    'id' => $u->id,
                    'sales_achievement_id' => $u->sales_achievement_id,
                    'unlocked_at' => $u->unlocked_at?->toIso8601String(),
                    'metric_value_at_unlock' => (float) $u->metric_value_at_unlock,
                    'metric_type' => $u->metric_type,
                    'threshold' => (float) $u->threshold_snapshot,
                    'name' => $u->name_snapshot,
                    'image' => $u->image_snapshot
                        ? ($storage->resolvePublicUrl((string) $u->image_snapshot) ?: null)
                        : null,
                    'reward_name' => $u->reward_name_snapshot,
                    'reward_description' => $u->reward_description_snapshot,
                    'reward_image' => $u->reward_image_snapshot
                        ? ($storage->resolvePublicUrl((string) $u->reward_image_snapshot) ?: null)
                        : null,
                    'reward_status' => $u->reward_status,
                    'reward_status_label' => $u->rewardStatusEnum()->label(),
                    'reward_sent_at' => $u->reward_sent_at?->toIso8601String(),
                    'reward_carrier' => $u->reward_carrier,
                    'reward_tracking_code' => $forAdmin ? $u->reward_tracking_code : (
                        $u->reward_status === 'sent' ? $u->reward_tracking_code : null
                    ),
                ];

                if ($forAdmin) {
                    $payload['reward_admin_notes'] = $u->reward_admin_notes;
                }

                return $payload;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *   id: int|null,
     *   threshold: float,
     *   slug: string,
     *   name: string,
     *   description: string|null,
     *   metric_type: string,
     *   image: string|null,
     *   reward_name: string|null,
     *   reward_description: string|null,
     *   reward_image: string|null,
     *   reward_internal_notes?: string|null,
     *   has_reward: bool
     * }>
     */
    public function getAchievementsCatalog(?string $metricType = null, bool $includeInternalNotes = false): array
    {
        if (Schema::hasTable('sales_achievements')) {
            $query = SalesAchievement::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('threshold');

            if ($metricType !== null) {
                $query->where('metric_type', $metricType);
            }

            $rows = $query->get();

            if ($rows->isNotEmpty()) {
                $storage = app(StorageService::class);

                return $rows->map(function (SalesAchievement $row) use ($storage, $includeInternalNotes) {
                    $item = [
                        'id' => $row->id,
                        'threshold' => (float) $row->threshold,
                        'slug' => (string) $row->slug,
                        'name' => (string) $row->name,
                        'description' => $row->description,
                        'metric_type' => (string) ($row->metric_type ?: 'revenue'),
                        'image' => $row->image
                            ? $storage->resolvePublicUrl((string) $row->image) ?: null
                            : null,
                        'reward_name' => $row->reward_name,
                        'reward_description' => $row->reward_description,
                        'reward_image' => $row->reward_image
                            ? $storage->resolvePublicUrl((string) $row->reward_image) ?: null
                            : null,
                        'has_reward' => $row->hasReward(),
                    ];
                    if ($includeInternalNotes) {
                        $item['reward_internal_notes'] = $row->reward_internal_notes;
                    }

                    return $item;
                })->values()->all();
            }
        }

        $fallback = config('conquistas.achievements', []);

        return array_values(array_map(fn (array $a) => [
            'id' => null,
            'threshold' => (float) ($a['threshold'] ?? 0),
            'slug' => (string) ($a['slug'] ?? ''),
            'name' => (string) ($a['name'] ?? ''),
            'description' => null,
            'metric_type' => 'revenue',
            'image' => ! empty($a['image']) ? (string) $a['image'] : null,
            'reward_name' => null,
            'reward_description' => null,
            'reward_image' => null,
            'has_reward' => false,
        ], $fallback));
    }
}
