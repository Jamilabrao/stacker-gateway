<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SalesAchievementMetricType;
use App\Enums\SalesAchievementRewardStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountManager;
use App\Models\SalesAchievement;
use App\Models\SalesAchievementUnlock;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Services\SalesAchievementRankingService;
use App\Services\SalesAchievementRewardStatusService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SalesAchievementsController extends Controller
{
    public function __construct(
        protected StorageService $storage,
        protected SalesAchievementRankingService $ranking,
        protected SalesAchievementRewardStatusService $rewardStatus,
    ) {}

    public function index(Request $request): Response
    {
        $tab = (string) $request->query('tab', 'conquistas');
        if (! in_array($tab, ['conquistas', 'relatorio', 'premiacoes'], true)) {
            $tab = 'conquistas';
        }

        $achievements = SalesAchievement::query()
            ->orderBy('sort_order')
            ->orderBy('threshold')
            ->get()
            ->map(fn (SalesAchievement $a) => $this->achievementPayload($a, includeInternal: true))
            ->values();

        $rankingPayload = [
            'data' => [],
            'links' => [],
            'meta' => ['from' => 0, 'to' => 0, 'total' => 0, 'current_page' => 1, 'last_page' => 1, 'per_page' => 25],
            'filters' => [],
        ];
        if ($tab === 'relatorio') {
            $result = $this->ranking->paginate($request);
            $paginator = $result['rows'];
            $rankingPayload = [
                'data' => $paginator->items(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => array_merge($result['meta'], [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                ]),
                'filters' => $result['filters'],
            ];
        }

        $rewardsPayload = ['data' => [], 'meta' => ['total' => 0]];
        if ($tab === 'premiacoes' && Schema::hasTable('sales_achievement_unlocks')) {
            $status = $request->query('reward_status');
            $query = SalesAchievementUnlock::query()
                ->whereNotNull('reward_name_snapshot')
                ->with(['tenant:id,name,email', 'achievement:id,slug,name'])
                ->orderByDesc('unlocked_at');
            if ($status) {
                $query->where('reward_status', $status);
            }
            $page = $query->paginate((int) $request->query('per_page', 25));
            $rewardsPayload = [
                'data' => collect($page->items())->map(fn (SalesAchievementUnlock $u) => [
                    'id' => $u->id,
                    'tenant_id' => $u->tenant_id,
                    'merchant_name' => $u->tenant?->name,
                    'merchant_email' => $u->tenant?->email,
                    'achievement_name' => $u->name_snapshot,
                    'reward_name' => $u->reward_name_snapshot,
                    'reward_status' => $u->reward_status,
                    'reward_status_label' => $u->rewardStatusEnum()->label(),
                    'unlocked_at' => $u->unlocked_at?->toIso8601String(),
                    'user_id' => $u->tenant_id,
                ])->values(),
                'meta' => [
                    'total' => $page->total(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                ],
            ];
        }

        return Inertia::render('Platform/Conquistas/Index', [
            'tab' => $tab,
            'achievements' => $achievements,
            'ranking' => $rankingPayload,
            'rewards' => $rewardsPayload,
            'metric_types' => collect(SalesAchievementMetricType::cases())
                ->map(fn (SalesAchievementMetricType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    'selectable' => $t->isImplemented(),
                ])
                ->values(),
            'reward_statuses' => collect(SalesAchievementRewardStatus::operationalValues())
                ->map(fn (string $v) => [
                    'value' => $v,
                    'label' => SalesAchievementRewardStatus::from($v)->label(),
                ])
                ->values(),
            'account_managers' => Schema::hasTable('account_managers')
                ? AccountManager::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateAchievement($request);

        $achievement = SalesAchievement::query()->create($validated);

        PlatformAuditService::log('achievement.created', [
            'sales_achievement_id' => $achievement->id,
            'slug' => $achievement->slug,
            'threshold' => $achievement->threshold,
            'reward_name' => $achievement->reward_name,
        ], $request);

        return response()->json(['ok' => true, 'achievement' => $this->achievementPayload($achievement, true)]);
    }

    public function update(Request $request, SalesAchievement $salesAchievement): JsonResponse
    {
        $validated = $this->validateAchievement($request, $salesAchievement);
        $salesAchievement->update($validated);

        PlatformAuditService::log('achievement.updated', [
            'sales_achievement_id' => $salesAchievement->id,
            'slug' => $salesAchievement->slug,
            'threshold' => $salesAchievement->threshold,
            'reward_name' => $salesAchievement->reward_name,
        ], $request);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, SalesAchievement $salesAchievement): JsonResponse
    {
        $id = $salesAchievement->id;
        $slug = $salesAchievement->slug;
        $salesAchievement->delete();

        PlatformAuditService::log('achievement.deleted', [
            'sales_achievement_id' => $id,
            'slug' => $slug,
        ], $request);

        return response()->json(['ok' => true]);
    }

    public function uploadImage(Request $request, SalesAchievement $salesAchievement): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp,gif,svg'],
            'kind' => ['nullable', 'string', Rule::in(['badge', 'reward'])],
        ]);

        $kind = $request->input('kind', 'badge');
        $folder = $kind === 'reward' ? 'conquistas/rewards' : 'conquistas';
        $uploaded = $this->storage->storeUploadedPublicFile($request->file('file'), $folder);

        if ($kind === 'reward') {
            $salesAchievement->update(['reward_image' => $uploaded['path']]);
        } else {
            $salesAchievement->update(['image' => $uploaded['path']]);
        }

        return response()->json(['ok' => true, 'url' => $uploaded['url'], 'path' => $uploaded['path']]);
    }

    public function updateUnlockRewardStatus(
        Request $request,
        SalesAchievementUnlock $unlock,
    ): JsonResponse {
        $validated = $request->validate([
            'reward_status' => ['required', 'string', Rule::in(SalesAchievementRewardStatus::values())],
            'note' => ['nullable', 'string', 'max:2000'],
            'reward_carrier' => ['nullable', 'string', 'max:120'],
            'reward_tracking_code' => ['nullable', 'string', 'max:120'],
            'reward_admin_notes' => ['nullable', 'string', 'max:5000'],
            'reward_sent_at' => ['nullable', 'date'],
        ]);

        try {
            $updated = $this->rewardStatus->updateStatus(
                $unlock,
                $validated['reward_status'],
                $request->user(),
                $validated,
                $request
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'unlock' => [
                'id' => $updated->id,
                'reward_status' => $updated->reward_status,
                'reward_status_label' => $updated->rewardStatusEnum()->label(),
                'reward_sent_at' => $updated->reward_sent_at?->toIso8601String(),
                'reward_carrier' => $updated->reward_carrier,
                'reward_tracking_code' => $updated->reward_tracking_code,
                'reward_admin_notes' => $updated->reward_admin_notes,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAchievement(Request $request, ?SalesAchievement $existing = null): array
    {
        $validated = $request->validate([
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('sales_achievements', 'slug')->ignore($existing?->id),
            ],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'metric_type' => ['nullable', 'string', Rule::in(SalesAchievementMetricType::selectableValues())],
            'threshold' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'reward_name' => ['nullable', 'string', 'max:180'],
            'reward_description' => ['nullable', 'string', 'max:2000'],
            'reward_image' => ['nullable', 'string', 'max:2048'],
            'reward_internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return [
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'metric_type' => $validated['metric_type'] ?? SalesAchievementMetricType::Revenue->value,
            'threshold' => (float) $validated['threshold'],
            'image' => $this->normalizeImageInput($validated['image'] ?? null),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? ($existing ? false : true)),
            'reward_name' => $validated['reward_name'] ?? null,
            'reward_description' => $validated['reward_description'] ?? null,
            'reward_image' => $this->normalizeImageInput($validated['reward_image'] ?? null),
            'reward_internal_notes' => $validated['reward_internal_notes'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function achievementPayload(SalesAchievement $a, bool $includeInternal = false): array
    {
        $payload = [
            'id' => $a->id,
            'slug' => $a->slug,
            'name' => $a->name,
            'description' => $a->description,
            'metric_type' => $a->metric_type ?: 'revenue',
            'threshold' => (float) $a->threshold,
            'image' => $this->resolveImageUrl($a->image),
            'image_path' => $a->image,
            'sort_order' => (int) $a->sort_order,
            'is_active' => (bool) $a->is_active,
            'reward_name' => $a->reward_name,
            'reward_description' => $a->reward_description,
            'reward_image' => $this->resolveImageUrl($a->reward_image),
            'reward_image_path' => $a->reward_image,
            'has_reward' => $a->hasReward(),
        ];
        if ($includeInternal) {
            $payload['reward_internal_notes'] = $a->reward_internal_notes;
        }

        return $payload;
    }

    private function resolveImageUrl(mixed $stored): ?string
    {
        if (! is_string($stored) || trim($stored) === '') {
            return null;
        }
        $url = $this->storage->resolvePublicUrl($stored);

        return $url !== '' ? $url : null;
    }

    private function normalizeImageInput(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $path = parse_url($value, PHP_URL_PATH);
            if (is_string($path) && str_contains($path, '/storage/')) {
                return ltrim(substr($path, strpos($path, '/storage/') + strlen('/storage/')), '/');
            }
        }

        return $value;
    }
}
