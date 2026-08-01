<?php

namespace App\Services;

use App\Enums\SalesAchievementMetricType;
use App\Enums\SalesAchievementRewardStatus;
use App\Models\SalesAchievementUnlock;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class SalesAchievementRankingService
{
    public function __construct(
        protected SalesAchievementsService $achievements,
        protected StorageService $storage,
    ) {}

    /**
     * @return array{rows: LengthAwarePaginator, filters: array<string, mixed>, meta: array<string, mixed>}
     */
    public function paginate(Request $request): array
    {
        $metricType = (string) $request->query('metric_type', 'revenue');
        if (! in_array($metricType, SalesAchievementMetricType::selectableValues(), true)) {
            $metricType = 'revenue';
        }

        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $sort = (string) $request->query('sort', 'current_value');
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortWhitelist = [
            'current_value', 'progress_percent', 'remaining', 'name',
            'last_unlocked_at', 'reward_status', 'position',
        ];
        if (! in_array($sort, $sortWhitelist, true)) {
            $sort = 'current_value';
        }

        $filters = [
            'metric_type' => $metricType,
            'achievement_id' => $request->query('achievement_id') ? (int) $request->query('achievement_id') : null,
            'account_status' => $request->query('account_status') ?: null,
            'account_manager_id' => $request->query('account_manager_id') ? (int) $request->query('account_manager_id') : null,
            'reward_status' => $request->query('reward_status') ?: null,
            'q' => trim((string) $request->query('q', '')),
            'period_from' => $request->query('period_from') ?: null,
            'period_to' => $request->query('period_to') ?: null,
            'sort' => $sort,
            'direction' => $direction,
            'per_page' => $perPage,
        ];

        $query = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->with(['accountManager:id,name']);

        if ($filters['account_status']) {
            $query->where('account_status', $filters['account_status']);
        }
        if ($filters['account_manager_id']) {
            $query->where('account_manager_id', $filters['account_manager_id']);
        }
        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(function ($builder) use ($like) {
                $builder->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
        }

        $needsUnlockFilter = Schema::hasTable('sales_achievement_unlocks')
            && ($filters['achievement_id'] || $filters['reward_status'] || $filters['period_from'] || $filters['period_to']);

        if ($needsUnlockFilter) {
            $tenantIdsMatching = SalesAchievementUnlock::query()
                ->where('metric_type', $metricType)
                ->when($filters['achievement_id'], fn ($q) => $q->where('sales_achievement_id', $filters['achievement_id']))
                ->when($filters['reward_status'], fn ($q) => $q->where('reward_status', $filters['reward_status']))
                ->when($filters['period_from'], fn ($q) => $q->whereDate('unlocked_at', '>=', $filters['period_from']))
                ->when($filters['period_to'], fn ($q) => $q->whereDate('unlocked_at', '<=', $filters['period_to']))
                ->distinct()
                ->pluck('tenant_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $query->where(function ($q) use ($tenantIdsMatching) {
                $q->whereIn('id', $tenantIdsMatching)
                    ->orWhereIn('tenant_id', $tenantIdsMatching);
            });
        }

        $users = $query->orderBy('name')->get();
        $tenantIds = $users->map(fn (User $u) => (int) ($u->tenant_id ?: $u->id))->unique()->values()->all();
        $totals = $this->achievements->getValidSalesTotalsForTenants($tenantIds);
        $catalog = $this->achievements->getAchievementsCatalog($metricType, includeInternalNotes: false);

        $unlocksByTenant = [];
        if (Schema::hasTable('sales_achievement_unlocks') && $tenantIds !== []) {
            foreach (
                SalesAchievementUnlock::query()
                    ->whereIn('tenant_id', $tenantIds)
                    ->where('metric_type', $metricType)
                    ->orderByDesc('unlocked_at')
                    ->get() as $row
            ) {
                $unlocksByTenant[(int) $row->tenant_id][] = $row;
            }
        }

        $rows = [];
        foreach ($users as $user) {
            $tenantId = (int) ($user->tenant_id ?: $user->id);
            $current = (float) ($totals[$tenantId] ?? 0);
            $tenantUnlocks = $unlocksByTenant[$tenantId] ?? [];
            $unlockedIds = [];
            foreach ($tenantUnlocks as $u) {
                $unlockedIds[(int) $u->sales_achievement_id] = true;
            }

            $currentAch = null;
            $nextAch = null;
            foreach ($catalog as $a) {
                $id = (int) ($a['id'] ?? 0);
                $unlocked = $current >= (float) $a['threshold'] || isset($unlockedIds[$id]);
                if ($unlocked) {
                    $currentAch = $a;
                } elseif ($nextAch === null) {
                    $nextAch = $a;
                }
            }

            $allCompleted = $nextAch === null && $currentAch !== null;

            $target = $nextAch ? (float) $nextAch['threshold'] : (float) ($currentAch['threshold'] ?? 0);
            $percent = $allCompleted
                ? 100.0
                : ($target > 0 ? min(100, max(0, ($current / $target) * 100)) : 0.0);
            $remaining = $allCompleted ? 0.0 : max(0, round($target - $current, 2));

            $lastUnlock = $tenantUnlocks[0] ?? null;
            $openReward = null;
            foreach ($tenantUnlocks as $u) {
                if ($u->hasReward() && in_array($u->reward_status, SalesAchievementRewardStatus::operationalValues(), true)) {
                    $openReward = $u;
                    break;
                }
            }

            $rows[] = [
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'account_status' => $user->account_status ?? 'approved',
                'account_manager' => $user->accountManager
                    ? ['id' => $user->accountManager->id, 'name' => $user->accountManager->name]
                    : null,
                'current_value' => $current,
                'target_value' => $allCompleted ? (float) ($currentAch['threshold'] ?? 0) : $target,
                'progress_percent' => round($percent, 1),
                'remaining' => $remaining,
                'all_completed' => $allCompleted,
                'next_achievement' => $nextAch,
                'next_reward_name' => $nextAch['reward_name'] ?? null,
                'last_achievement' => $lastUnlock ? [
                    'name' => $lastUnlock->name_snapshot,
                    'unlocked_at' => $lastUnlock->unlocked_at?->toIso8601String(),
                    'image' => $lastUnlock->image_snapshot
                        ? ($this->storage->resolvePublicUrl((string) $lastUnlock->image_snapshot) ?: null)
                        : null,
                ] : ($currentAch ? [
                    'name' => $currentAch['name'],
                    'unlocked_at' => null,
                    'image' => $currentAch['image'] ?? null,
                ] : null),
                'last_unlocked_at' => $lastUnlock?->unlocked_at?->toIso8601String(),
                'reward_status' => $openReward?->reward_status,
                'reward_status_label' => $openReward
                    ? SalesAchievementRewardStatus::tryFrom((string) $openReward->reward_status)?->label()
                    : null,
            ];
        }

        usort($rows, function (array $a, array $b) use ($sort, $direction) {
            if ($sort === 'position') {
                $cmp = ((float) $a['current_value']) <=> ((float) $b['current_value']);
            } elseif (in_array($sort, ['name', 'last_unlocked_at', 'reward_status'], true)) {
                $cmp = strcmp((string) ($a[$sort] ?? ''), (string) ($b[$sort] ?? ''));
            } else {
                $cmp = ((float) ($a[$sort] ?? 0)) <=> ((float) ($b[$sort] ?? 0));
            }

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        foreach ($rows as $i => &$row) {
            $row['position'] = $i + 1;
        }
        unset($row);

        $page = max(1, (int) $request->query('page', 1));
        $total = count($rows);
        $slice = array_values(array_slice($rows, ($page - 1) * $perPage, $perPage));

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return [
            'rows' => $paginator,
            'filters' => $filters,
            'meta' => [
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => min($page * $perPage, $total),
                'total' => $total,
            ],
        ];
    }
}
