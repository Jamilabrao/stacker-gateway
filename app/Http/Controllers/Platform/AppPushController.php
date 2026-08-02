<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AccountManager;
use App\Models\PanelPushCampaign;
use App\Models\PanelPushDailySummaryLog;
use App\Models\PanelPushSubscription;
use App\Models\User;
use App\Services\PanelPushCampaignService;
use App\Services\PanelPushService;
use App\Support\DailySalesPushSettings;
use App\Support\PanelPushSettings;
use App\Support\VapidEnvKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AppPushController extends Controller
{
    public function clientConfig(): JsonResponse
    {
        if (! PanelPushSettings::isFcmConfigured()) {
            return response()->json(['enabled' => false]);
        }

        $client = PanelPushSettings::publicClientConfig();

        return response()->json([
            'enabled' => true,
            'push_provider' => PanelPushSettings::PROVIDER_FCM,
            'firebase' => $client['firebase'] ?? null,
            'firebase_web_vapid_key' => $client['firebase_web_vapid_key'] ?? null,
        ]);
    }

    public function data(): JsonResponse
    {
        $staleStats = $this->staleSubscriptionStats();

        return response()->json([
            'push' => PanelPushSettings::adminPayload(),
            'subscribers_count' => PanelPushSubscription::query()->count(),
            'subscribers_by_provider' => [
                'vapid' => PanelPushSubscription::query()->where('provider', PanelPushSubscription::PROVIDER_VAPID)->orWhereNull('provider')->count(),
                'fcm' => PanelPushSubscription::query()->where('provider', PanelPushSubscription::PROVIDER_FCM)->count(),
            ],
            'stale_subscriptions_count' => $staleStats['stale'],
            'idle_subscriptions_count' => $staleStats['idle'],
            'daily_sales' => DailySalesPushSettings::forAdminForm(),
            'audiences' => PanelPushCampaign::audienceLabels(),
            'campaign_statuses' => PanelPushCampaign::statusLabels(),
            'account_managers' => Schema::hasTable('account_managers')
                ? AccountManager::query()->active()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (AccountManager $m) => ['id' => $m->id, 'name' => $m->name])
                    ->values()
                    ->all()
                : [],
            'sound_notice' => 'O som das notificações é definido pelo navegador e pelo sistema operacional do dispositivo. Upload de MP3 personalizado não é suportado no Web Push/PWA.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_provider' => ['nullable', 'string', Rule::in([PanelPushSettings::PROVIDER_VAPID, PanelPushSettings::PROVIDER_FCM])],
            'pwa_vapid_public' => ['nullable', 'string', 'max:2048'],
            'pwa_vapid_private' => ['nullable', 'string', 'max:4096'],
            'firebase_project_id' => ['nullable', 'string', 'max:255'],
            'firebase_api_key' => ['nullable', 'string', 'max:512'],
            'firebase_messaging_sender_id' => ['nullable', 'string', 'max:64'],
            'firebase_app_id' => ['nullable', 'string', 'max:128'],
            'firebase_web_vapid_key' => ['nullable', 'string', 'max:2048'],
        ]);

        PanelPushSettings::saveGlobal($validated);

        return response()->json(['ok' => true, 'push' => PanelPushSettings::adminPayload()]);
    }

    public function uploadServiceAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:64', 'mimes:json,txt'],
        ]);

        $json = file_get_contents($validated['file']->getRealPath());
        if (! is_string($json) || trim($json) === '') {
            return response()->json(['message' => 'Arquivo vazio.'], 422);
        }

        try {
            PanelPushSettings::storeFirebaseServiceAccount($json);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'push' => PanelPushSettings::adminPayload()]);
    }

    public function generateVapid(): JsonResponse
    {
        $staleBefore = $this->staleSubscriptionStats()['stale'];

        $keys = PanelPushSettings::generateVapidKeyPair();
        PanelPushSettings::storeVapidKeys($keys['publicKey'], $keys['privateKey']);
        \App\Support\PwaVapidEnvSync::writeKeysToDotEnv($keys['publicKey'], $keys['privateKey']);

        $staleAfter = $this->staleSubscriptionStats()['stale'];

        return response()->json([
            'ok' => true,
            'public_key' => $keys['publicKey'],
            'push' => PanelPushSettings::adminPayload(),
            'stale_subscriptions_count' => $staleAfter,
            'message' => 'Par VAPID gerado. '.$staleAfter.' inscrição(ões) precisarão reativar notificações no painel.',
            'had_stale_before' => $staleBefore,
        ]);
    }

    public function clearOtherProviderSubscriptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in([PanelPushSubscription::PROVIDER_VAPID, PanelPushSubscription::PROVIDER_FCM])],
        ]);

        $deleted = PanelPushSubscription::query()
            ->where('provider', $validated['provider'])
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    public function subscribers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['nullable', 'string', Rule::in([PanelPushSubscription::PROVIDER_VAPID, PanelPushSubscription::PROVIDER_FCM])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = PanelPushSubscription::query()
            ->with('user:id,name,email,tenant_id')
            ->orderByDesc('updated_at');

        if (! empty($validated['provider'])) {
            $query->where('provider', $validated['provider']);
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('email', 'like', $search)->orWhere('name', 'like', $search);
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(function (PanelPushSubscription $sub) {
                $idle = $sub->last_used_at === null
                    || $sub->last_used_at->lt(now()->subDays(7));

                return [
                    'id' => $sub->id,
                    'provider' => $sub->provider ?? PanelPushSubscription::PROVIDER_VAPID,
                    'user_id' => $sub->user_id,
                    'user_name' => $sub->user?->name,
                    'user_email' => $sub->user?->email,
                    'tenant_id' => $sub->tenant_id,
                    'device_label' => $sub->device_label,
                    'endpoint_preview' => $sub->isFcm()
                        ? (strlen((string) $sub->fcm_token) > 24 ? substr((string) $sub->fcm_token, 0, 12).'…' : $sub->fcm_token)
                        : (strlen((string) $sub->endpoint) > 40 ? substr((string) $sub->endpoint, 0, 40).'…' : $sub->endpoint),
                    'last_used_at' => $sub->last_used_at?->toIso8601String(),
                    'created_at' => $sub->created_at?->toIso8601String(),
                    'updated_at' => $sub->updated_at?->toIso8601String(),
                    'is_stale' => ! $sub->isValidForPush(),
                    'is_idle' => $idle,
                ];
            }),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function destroySubscriber(PanelPushSubscription $subscription): JsonResponse
    {
        $subscription->delete();

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, PanelPushService $panelPushService): JsonResponse
    {
        if (! PanelPushSettings::isPushEnabled()) {
            return response()->json(['ok' => false, 'message' => 'Configure o provedor de push antes de testar.'], 422);
        }

        $user = $request->user();
        $subs = PanelPushSubscription::query()->where('user_id', $user->id)->get();
        if ($subs->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message' => 'Nenhuma inscrição neste usuário. Abra o painel no PWA e ative notificações.',
            ], 422);
        }

        $result = $panelPushService->sendToSubscriptions(
            $subs,
            'Teste Getfy',
            'Notificação de teste enviada pelo painel da plataforma.',
            '/dashboard'
        );

        $sent = (int) ($result['sent'] ?? 0);

        return response()->json([
            'ok' => $sent > 0,
            'message' => $sent > 0
                ? null
                : $this->formatPushFailureMessage($result),
            'result' => $result,
        ]);
    }

    /**
     * @return array{stale: int, idle: int}
     */
    private function staleSubscriptionStats(): array
    {
        $stale = 0;
        $idle = 0;

        PanelPushSubscription::query()->each(function (PanelPushSubscription $sub) use (&$stale, &$idle): void {
            if (! $sub->isValidForPush()) {
                $stale++;
            }
            if ($sub->last_used_at === null || $sub->last_used_at->lt(now()->subDays(7))) {
                $idle++;
            }
        });

        return ['stale' => $stale, 'idle' => $idle];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function formatPushFailureMessage(array $result): string
    {
        $failed = (int) ($result['failed'] ?? 0);
        $expired = (int) ($result['expired'] ?? 0);
        $invalid = (int) ($result['invalid'] ?? 0);
        $total = (int) ($result['total'] ?? 0);

        if ($total === 0) {
            return 'Nenhuma inscrição compatível com o provedor ativo.';
        }
        if ($expired > 0) {
            return "Nenhum push entregue. {$expired} inscrição(ões) expirada(s) — peça reativação no painel.";
        }
        if ($invalid > 0) {
            return "Nenhum push entregue. {$invalid} inscrição(ões) inválida(s).";
        }
        if ($failed > 0) {
            return "Nenhum push entregue. {$failed} falha(s) — verifique chaves VAPID e logs do servidor.";
        }

        return 'Nenhum push entregue. Verifique o provedor e a inscrição.';
    }

    public function sendBroadcast(Request $request, PanelPushCampaignService $campaigns): JsonResponse
    {
        if (! PanelPushSettings::isPushEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => 'Push não configurado. Configure em App → Notificações push.',
                'result' => ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 0],
            ], 422);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:2048'],
            'audience' => ['nullable', 'string', 'max:64'],
            'audience_filters' => ['nullable', 'array'],
            'send_mode' => ['nullable', 'string', Rule::in(['now', 'scheduled'])],
            'scheduled_local' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'silent' => ['nullable', 'boolean'],
            'confirm_global' => ['nullable', 'boolean'],
        ]);

        $audience = $validated['audience'] ?? PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS;
        if (in_array($audience, [PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS, PanelPushCampaign::AUDIENCE_ALL_MERCHANTS], true)
            && ! ($validated['confirm_global'] ?? false)
            && ($validated['send_mode'] ?? 'now') === 'now') {
            $approx = PanelPushSubscription::query()->count();

            return response()->json([
                'ok' => false,
                'needs_confirmation' => true,
                'message' => "Esta notificação será enviada para aproximadamente {$approx} dispositivos. Confirme para continuar.",
                'approx_devices' => $approx,
            ], 422);
        }

        try {
            $campaign = $campaigns->create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'target_url' => $validated['url'] ?? null,
                'audience' => $audience,
                'audience_filters' => $validated['audience_filters'] ?? [],
                'send_mode' => $validated['send_mode'] ?? 'now',
                'scheduled_local' => $validated['scheduled_local'] ?? null,
                'timezone' => $validated['timezone'] ?? config('app.timezone', 'America/Sao_Paulo'),
                'silent' => (bool) ($validated['silent'] ?? false),
            ], $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $campaign->send_mode === PanelPushCampaign::MODE_SCHEDULED
                ? 'Notificação agendada.'
                : 'Notificação enfileirada para envio.',
            'campaign' => $this->campaignPayload($campaign),
        ]);
    }

    public function campaigns(Request $request): JsonResponse
    {
        if (! Schema::hasTable('panel_push_campaigns')) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1, 'per_page' => 25]]);
        }

        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $query = PanelPushCampaign::query()->with('creator:id,name')->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where('title', 'like', $like);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page');

        return response()->json([
            'data' => collect($paginator->items())->map(fn (PanelPushCampaign $c) => $this->campaignPayload($c))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function showCampaign(PanelPushCampaign $campaign): JsonResponse
    {
        $campaign->load('creator:id,name');

        return response()->json(['campaign' => $this->campaignPayload($campaign, detailed: true)]);
    }

    public function updateCampaign(Request $request, PanelPushCampaign $campaign, PanelPushCampaignService $campaigns): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'body' => ['sometimes', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:2048'],
            'audience' => ['nullable', 'string', 'max:64'],
            'audience_filters' => ['nullable', 'array'],
            'scheduled_local' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'silent' => ['nullable', 'boolean'],
        ]);

        try {
            $updated = $campaigns->update($campaign, [
                'title' => $validated['title'] ?? $campaign->title,
                'body' => $validated['body'] ?? $campaign->body,
                'target_url' => $validated['url'] ?? $campaign->target_url,
                'audience' => $validated['audience'] ?? $campaign->audience,
                'audience_filters' => $validated['audience_filters'] ?? $campaign->audience_filters,
                'scheduled_local' => $validated['scheduled_local'] ?? null,
                'timezone' => $validated['timezone'] ?? $campaign->timezone,
                'silent' => $validated['silent'] ?? $campaign->silent,
            ], $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'campaign' => $this->campaignPayload($updated)]);
    }

    public function cancelCampaign(Request $request, PanelPushCampaign $campaign, PanelPushCampaignService $campaigns): JsonResponse
    {
        try {
            $updated = $campaigns->cancel($campaign, $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'campaign' => $this->campaignPayload($updated)]);
    }

    public function updateDailySalesSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'daily_sales_push_enabled' => ['nullable'],
            'daily_sales_push_time' => ['nullable', 'string', 'max:5'],
            'daily_sales_push_timezone' => ['nullable', 'string', 'max:64'],
            'daily_sales_push_only_when_has_sales' => ['nullable'],
        ]);

        DailySalesPushSettings::persist($validated, $request);

        return response()->json(['ok' => true, 'daily_sales' => DailySalesPushSettings::forAdminForm()]);
    }

    public function dailySummaryHistory(Request $request): JsonResponse
    {
        if (! Schema::hasTable('panel_push_daily_summary_logs')) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $paginator = PanelPushDailySummaryLog::query()
            ->orderByDesc('reference_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (PanelPushDailySummaryLog $log) => [
                'id' => $log->id,
                'tenant_id' => $log->tenant_id,
                'reference_date' => $log->reference_date?->toDateString(),
                'orders_count' => $log->orders_count,
                'orders_total' => (float) $log->orders_total,
                'status' => $log->status,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignPayload(PanelPushCampaign $campaign, bool $detailed = false): array
    {
        $payload = [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'body' => $campaign->body,
            'target_url' => $campaign->target_url,
            'audience' => $campaign->audience,
            'audience_label' => PanelPushCampaign::audienceLabels()[$campaign->audience] ?? $campaign->audience,
            'audience_filters' => $campaign->audience_filters,
            'send_mode' => $campaign->send_mode,
            'scheduled_at' => $campaign->scheduled_at?->utc()->toIso8601String(),
            'scheduled_local' => $campaign->scheduled_at
                ? $campaign->scheduled_at->timezone($campaign->timezone ?: 'UTC')->format('Y-m-d H:i:s')
                : null,
            'timezone' => $campaign->timezone,
            'silent' => (bool) $campaign->silent,
            'status' => $campaign->status,
            'status_label' => PanelPushCampaign::statusLabels()[$campaign->status] ?? $campaign->status,
            'eligible_count' => $campaign->eligible_count,
            'sent_count' => $campaign->sent_count,
            'failed_count' => $campaign->failed_count,
            'invalid_count' => $campaign->invalid_count,
            'expired_count' => $campaign->expired_count,
            'created_by' => $campaign->creator?->name,
            'created_at' => $campaign->created_at?->toIso8601String(),
            'processing_started_at' => $campaign->processing_started_at?->toIso8601String(),
            'completed_at' => $campaign->completed_at?->toIso8601String(),
            'cancelled_at' => $campaign->cancelled_at?->toIso8601String(),
            'can_edit' => $campaign->isEditable(),
            'can_cancel' => $campaign->isCancellable(),
        ];

        if ($detailed) {
            $payload['last_error'] = $campaign->last_error;
            $payload['result_meta'] = $campaign->result_meta;
        }

        return $payload;
    }
}
