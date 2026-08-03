<?php

namespace App\Services;

use App\Exceptions\TransientInfrastructureException;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

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

        Log::info('push_campaign_created', $this->logContext($campaign, [
            'phase' => 'created',
        ]));

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
     * Remove campanhas do histórico (não remove inscrições push dos dispositivos).
     *
     * @param  array<int>|null  $ids
     * @return array{deleted: int}
     */
    public function deleteHistory(?array $ids = null, bool $all = false, ?Request $request = null): array
    {
        $query = PanelPushCampaign::query()
            ->where('status', '!=', PanelPushCampaign::STATUS_PROCESSING);

        if ($all) {
            // Histórico já efetuado / encerrado (não remove agendadas futuras pendentes).
            $query->whereIn('status', [
                PanelPushCampaign::STATUS_SENT,
                PanelPushCampaign::STATUS_PARTIALLY_SENT,
                PanelPushCampaign::STATUS_FAILED,
                PanelPushCampaign::STATUS_CANCELLED,
            ]);
        } elseif (is_array($ids) && $ids !== []) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $query->whereIn('id', $ids);
        } else {
            throw new InvalidArgumentException('Informe ids ou all=true para limpar o histórico.');
        }

        $deleted = 0;
        $query->orderBy('id')->chunkById(100, function ($rows) use (&$deleted) {
            foreach ($rows as $row) {
                /** @var PanelPushCampaign $row */
                if ($row->status === PanelPushCampaign::STATUS_PROCESSING) {
                    continue;
                }
                $row->delete();
                $deleted++;
            }
        });

        PlatformAuditService::log('push.history_cleared', [
            'deleted' => $deleted,
            'all' => $all,
            'ids' => $ids,
        ], $request);

        Log::info('push_campaign_history_cleared', [
            'deleted' => $deleted,
            'all' => $all,
            'ids' => $ids,
        ]);

        return ['deleted' => $deleted];
    }

    /**
     * Claim atômico + envio.
     *
     * @throws TransientInfrastructureException
     */
    public function process(int $campaignId): void
    {
        try {
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
                    'last_error' => null,
                ])->save();

                return $campaign;
            });
        } catch (Throwable $e) {
            if (TransientInfrastructureException::matches($e)) {
                throw TransientInfrastructureException::fromThrowable($e);
            }
            throw $e;
        }

        if (! $claimed) {
            return;
        }

        Log::info('push_campaign_processing', $this->logContext($claimed, [
            'phase' => 'processing_started',
        ]));

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
                // Idempotente: reprocessamento seguro não duplica inbox.
                PanelNotification::query()->firstOrCreate(
                    [
                        'user_id' => $userId,
                        'event_key' => 'campaign_'.$claimed->id.'_'.$userId,
                    ],
                    [
                        'tenant_id' => null,
                        'type' => 'system',
                        'title' => $claimed->title,
                        'body' => $claimed->body,
                        'url' => $claimed->target_url,
                    ]
                );
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

            Log::info('push_campaign_finished', $this->logContext($claimed->fresh(), [
                'phase' => 'finished',
                'recipients' => $eligible->count(),
                'sent' => $sent,
                'failed' => $failed,
                'invalid' => $invalid,
                'expired' => $expired,
                'final_status' => $final,
            ]));
        } catch (Throwable $e) {
            if (TransientInfrastructureException::matches($e)) {
                $this->releaseClaimForRetry($claimed, $e);
                Log::warning('push_campaign_transient_failure', $this->logContext($claimed, [
                    'phase' => 'transient_retry',
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]));
                throw TransientInfrastructureException::fromThrowable($e);
            }

            $claimed->forceFill([
                'status' => PanelPushCampaign::STATUS_FAILED,
                'completed_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            PlatformAuditService::log('push.failed', [
                'campaign_id' => $claimed->id,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::error('push_campaign_failed', $this->logContext($claimed->fresh(), [
                'phase' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]));
        }
    }

    /**
     * Devolve processing preso para scheduled (reinício de worker/container).
     */
    public function recoverStuckProcessing(int $olderThanMinutes = 10): int
    {
        $threshold = now()->subMinutes(max(1, $olderThanMinutes));

        $updated = PanelPushCampaign::query()
            ->where('status', PanelPushCampaign::STATUS_PROCESSING)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<=', $threshold);
            })
            ->update([
                'status' => PanelPushCampaign::STATUS_SCHEDULED,
                'last_error' => 'Reenfileirada após processing preso (worker/container reiniciado).',
            ]);

        if ($updated > 0) {
            Log::warning('push_campaign_stuck_recovered', [
                'recovered' => $updated,
                'older_than_minutes' => $olderThanMinutes,
            ]);
        }

        return $updated;
    }

    public function claimDueCampaigns(int $limit = 20): int
    {
        $this->recoverStuckProcessing(10);

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

        if ($ids->isNotEmpty()) {
            Log::info('push_campaigns_claimed_due', [
                'count' => $ids->count(),
                'ids' => $ids->values()->all(),
                'utc_now' => $utcNow,
            ]);
        }

        return $ids->count();
    }

    /**
     * Marca falha definitiva após esgotar retries do job.
     */
    public function markFailedAfterRetries(int $campaignId, string $error): void
    {
        $campaign = PanelPushCampaign::query()->find($campaignId);
        if (! $campaign) {
            return;
        }
        if (! in_array($campaign->status, [PanelPushCampaign::STATUS_SCHEDULED, PanelPushCampaign::STATUS_PROCESSING], true)) {
            return;
        }

        $campaign->forceFill([
            'status' => PanelPushCampaign::STATUS_FAILED,
            'completed_at' => now(),
            'last_error' => mb_substr($error, 0, 2000),
        ])->save();

        Log::error('push_campaign_failed_after_retries', $this->logContext($campaign, [
            'phase' => 'failed_after_retries',
            'error' => mb_substr($error, 0, 500),
        ]));
    }

    private function releaseClaimForRetry(PanelPushCampaign $campaign, Throwable $e): void
    {
        try {
            PanelPushCampaign::query()
                ->whereKey($campaign->id)
                ->where('status', PanelPushCampaign::STATUS_PROCESSING)
                ->update([
                    'status' => PanelPushCampaign::STATUS_SCHEDULED,
                    'last_error' => mb_substr('Retry temporário: '.$e->getMessage(), 0, 2000),
                ]);
        } catch (Throwable) {
            // Se o banco ainda estiver fora, o job relança e o recoverStuckProcessing recupera depois.
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(PanelPushCampaign $campaign, array $extra = []): array
    {
        return array_merge([
            'campaign_id' => $campaign->id,
            'send_mode' => $campaign->send_mode,
            'send_type' => $campaign->send_mode === PanelPushCampaign::MODE_NOW ? 'manual' : 'scheduled',
            'scheduled_at' => $campaign->scheduled_at?->utc()->toIso8601String(),
            'timezone' => $campaign->timezone,
            'status' => $campaign->status,
            'eligible_count' => $campaign->eligible_count,
            'sent_count' => $campaign->sent_count,
            'failed_count' => $campaign->failed_count,
            'db_host' => config('database.connections.'.config('database.default').'.host'),
            'db_port' => config('database.connections.'.config('database.default').'.port'),
        ], $extra);
    }
}
