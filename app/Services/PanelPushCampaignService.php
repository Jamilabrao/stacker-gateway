<?php

namespace App\Services;

use App\Jobs\ProcessPanelPushCampaignJob;
use App\Models\PanelNotification;
use App\Models\PanelPushCampaign;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Support\PanelPushAudienceResolver;
use App\Support\PanelPushTargetUrl;
use App\Support\UserPushPreferences;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PanelPushCampaignService
{
    public function __construct(
        protected PanelPushService $panelPushService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, ?Request $request = null): PanelPushCampaign
    {
        $title = trim(strip_tags((string) ($data['title'] ?? '')));
        $body = trim(strip_tags((string) ($data['body'] ?? '')));
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Título e mensagem são obrigatórios.');
        }
        if (mb_strlen($title) > 120 || mb_strlen($body) > 500) {
            throw new InvalidArgumentException('Título ou mensagem excedem o limite.');
        }

        try {
            $targetUrl = PanelPushTargetUrl::normalize($data['target_url'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw $e;
        }

        $audience = (string) ($data['audience'] ?? PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS);
        if (! array_key_exists($audience, PanelPushCampaign::audienceLabels())) {
            throw new InvalidArgumentException('Público inválido.');
        }

        $sendMode = ($data['send_mode'] ?? PanelPushCampaign::MODE_NOW) === PanelPushCampaign::MODE_SCHEDULED
            ? PanelPushCampaign::MODE_SCHEDULED
            : PanelPushCampaign::MODE_NOW;

        $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'America/Sao_Paulo'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = 'America/Sao_Paulo';
        }

        // UTC canônico: interpreta o horário local da campanha e persiste/compara em UTC.
        $scheduledAt = null;
        $status = PanelPushCampaign::STATUS_SCHEDULED;
        if ($sendMode === PanelPushCampaign::MODE_SCHEDULED) {
            $local = (string) ($data['scheduled_local'] ?? '');
            if ($local === '') {
                throw new InvalidArgumentException('Informe data e hora do agendamento.');
            }
            try {
                $scheduledAt = Carbon::parse($local, $timezone)->utc();
            } catch (\Throwable) {
                throw new InvalidArgumentException('Data/hora de agendamento inválida.');
            }
            if ($scheduledAt->lte(now('UTC')->subMinute())) {
                throw new InvalidArgumentException('Agende para um horário futuro.');
            }
        } else {
            $scheduledAt = now('UTC');
            $status = PanelPushCampaign::STATUS_SCHEDULED; // claim imediato pelo job
        }

        $filters = is_array($data['audience_filters'] ?? null) ? $data['audience_filters'] : [];

        $campaign = PanelPushCampaign::query()->create([
            'title' => $title,
            'body' => $body,
            'image_url' => null,
            'target_url' => $targetUrl,
            'audience' => $audience,
            'audience_filters' => $filters,
            'send_mode' => $sendMode,
            'scheduled_at' => $scheduledAt,
            'timezone' => $timezone,
            'silent' => (bool) ($data['silent'] ?? false),
            'status' => $status,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $actor->id,
        ]);

        PlatformAuditService::log(
            $sendMode === PanelPushCampaign::MODE_SCHEDULED ? 'push.scheduled' : 'push.created',
            [
                'campaign_id' => $campaign->id,
                'audience' => $audience,
                'send_mode' => $sendMode,
                'scheduled_at' => $scheduledAt?->toIso8601String(),
                'title' => $title,
            ],
            $request
        );

        if ($sendMode === PanelPushCampaign::MODE_NOW) {
            ProcessPanelPushCampaignJob::dispatch($campaign->id);
        }

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PanelPushCampaign $campaign, array $data, ?Request $request = null): PanelPushCampaign
    {
        if (! $campaign->isEditable()) {
            throw new InvalidArgumentException('Esta notificação não pode mais ser editada.');
        }

        $title = trim(strip_tags((string) ($data['title'] ?? $campaign->title)));
        $body = trim(strip_tags((string) ($data['body'] ?? $campaign->body)));
        $targetUrl = PanelPushTargetUrl::normalize($data['target_url'] ?? $campaign->target_url);

        $timezone = (string) ($data['timezone'] ?? $campaign->timezone);
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = $campaign->timezone;
        }

        $scheduledAt = $campaign->scheduled_at;
        if (! empty($data['scheduled_local'])) {
            $scheduledAt = Carbon::parse((string) $data['scheduled_local'], $timezone)->utc();
            if ($scheduledAt->lte(now('UTC')->subMinute())) {
                throw new InvalidArgumentException('Agende para um horário futuro.');
            }
        }

        $audience = (string) ($data['audience'] ?? $campaign->audience);
        if (! array_key_exists($audience, PanelPushCampaign::audienceLabels())) {
            $audience = $campaign->audience;
        }

        $campaign->fill([
            'title' => $title,
            'body' => $body,
            'target_url' => $targetUrl,
            'audience' => $audience,
            'audience_filters' => is_array($data['audience_filters'] ?? null)
                ? $data['audience_filters']
                : $campaign->audience_filters,
            'scheduled_at' => $scheduledAt,
            'timezone' => $timezone,
            'silent' => array_key_exists('silent', $data) ? (bool) $data['silent'] : $campaign->silent,
            'status' => PanelPushCampaign::STATUS_SCHEDULED,
        ])->save();

        PlatformAuditService::log('push.updated', [
            'campaign_id' => $campaign->id,
            'title' => $campaign->title,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
        ], $request);

        return $campaign->fresh();
    }

    public function cancel(PanelPushCampaign $campaign, ?Request $request = null): PanelPushCampaign
    {
        if (! $campaign->isCancellable()) {
            throw new InvalidArgumentException('Somente notificações agendadas podem ser canceladas.');
        }

        $updated = PanelPushCampaign::query()
            ->whereKey($campaign->id)
            ->where('status', PanelPushCampaign::STATUS_SCHEDULED)
            ->update([
                'status' => PanelPushCampaign::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

        if ($updated === 0) {
            throw new InvalidArgumentException('Não foi possível cancelar (status alterado).');
        }

        PlatformAuditService::log('push.cancelled', ['campaign_id' => $campaign->id], $request);

        return $campaign->fresh();
    }

    /**
     * Claim atômico + envio.
     */
    public function process(int $campaignId): void
    {
        $claimed = DB::transaction(function () use ($campaignId) {
            $campaign = PanelPushCampaign::query()->whereKey($campaignId)->lockForUpdate()->first();
            if (! $campaign) {
                return null;
            }
            if ($campaign->status !== PanelPushCampaign::STATUS_SCHEDULED) {
                return null;
            }
            // "Enviar agora" não espera scheduled_at; agendadas comparam em UTC.
            if ($campaign->send_mode !== PanelPushCampaign::MODE_NOW
                && $campaign->scheduled_at
                && $campaign->scheduled_at->utc()->gt(now('UTC'))) {
                return null;
            }

            $campaign->forceFill([
                'status' => PanelPushCampaign::STATUS_PROCESSING,
                'processing_started_at' => now(),
            ])->save();

            return $campaign;
        });

        if (! $claimed) {
            return;
        }

        try {
            $subs = PanelPushAudienceResolver::subscriptionsForCampaign($claimed);
            $eligible = $this->panelPushService->filterSubscriptionsForDelivery($subs);
            $claimed->eligible_count = $eligible->count();
            $claimed->save();

            // Respeita preferência de comunicados por usuário.
            $eligible = $eligible->filter(function ($sub) {
                return UserPushPreferences::allowsEvent((int) $sub->user_id, 'system');
            })->values();

            foreach ($eligible->pluck('user_id')->unique() as $userId) {
                PanelNotification::create([
                    'tenant_id' => null,
                    'user_id' => $userId,
                    'type' => 'system',
                    'title' => $claimed->title,
                    'body' => $claimed->body,
                    'url' => $claimed->target_url,
                    'event_key' => 'campaign_'.$claimed->id.'_'.$userId,
                ]);
            }

            $result = $this->panelPushService->sendToSubscriptions(
                $eligible,
                $claimed->title,
                $claimed->body,
                $claimed->target_url,
                'campaign_'.$claimed->id
            );

            $sent = (int) ($result['sent'] ?? 0);
            $failed = (int) ($result['failed'] ?? 0);
            $invalid = (int) ($result['invalid'] ?? 0);
            $expired = (int) ($result['expired'] ?? 0);

            $final = PanelPushCampaign::STATUS_SENT;
            if ($sent === 0 && ($failed + $invalid + $expired) > 0) {
                $final = PanelPushCampaign::STATUS_FAILED;
            } elseif ($sent > 0 && ($failed + $invalid + $expired) > 0) {
                $final = PanelPushCampaign::STATUS_PARTIALLY_SENT;
            } elseif ($sent === 0 && $eligible->isEmpty()) {
                $final = PanelPushCampaign::STATUS_SENT;
            }

            $claimed->forceFill([
                'sent_count' => $sent,
                'failed_count' => $failed,
                'invalid_count' => $invalid,
                'expired_count' => $expired,
                'status' => $final,
                'completed_at' => now(),
                'result_meta' => $result,
                'last_error' => null,
            ])->save();

            PlatformAuditService::log('push.sent', [
                'campaign_id' => $claimed->id,
                'status' => $final,
                'sent' => $sent,
                'failed' => $failed,
            ]);
        } catch (\Throwable $e) {
            $claimed->forceFill([
                'status' => PanelPushCampaign::STATUS_FAILED,
                'completed_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            PlatformAuditService::log('push.failed', [
                'campaign_id' => $claimed->id,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    public function claimDueCampaigns(int $limit = 20): int
    {
        // String UTC pura: evita o query builder reinterpretar Carbon no APP_TIMEZONE.
        $utcNow = now('UTC')->format('Y-m-d H:i:s');

        $ids = PanelPushCampaign::query()
            ->where('status', PanelPushCampaign::STATUS_SCHEDULED)
            ->where(function ($q) use ($utcNow) {
                $q->where('send_mode', PanelPushCampaign::MODE_NOW)
                    ->orWhere('scheduled_at', '<=', $utcNow);
            })
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessPanelPushCampaignJob::dispatch((int) $id);
        }

        return $ids->count();
    }
}
