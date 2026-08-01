<?php

namespace App\Services;

use App\Enums\SalesAchievementRewardStatus;
use App\Jobs\NotifySalesAchievementRewardStatusJob;
use App\Models\SalesAchievementRewardStatusHistory;
use App\Models\SalesAchievementUnlock;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SalesAchievementRewardStatusService
{
    public function updateStatus(
        SalesAchievementUnlock $unlock,
        string $toStatus,
        User $actor,
        array $extra = [],
        ?Request $request = null,
    ): SalesAchievementUnlock {
        $from = $unlock->rewardStatusEnum();
        $to = SalesAchievementRewardStatus::tryFrom($toStatus);
        if (! $to) {
            throw new InvalidArgumentException('Status de premiação inválido.');
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidArgumentException(
                "Transição inválida: {$from->label()} → {$to->label()}."
            );
        }

        if ($to === SalesAchievementRewardStatus::Cancelled && blank($extra['note'] ?? null)) {
            throw new InvalidArgumentException('Informe o motivo do cancelamento.');
        }

        if ($to === SalesAchievementRewardStatus::Sent) {
            $extra['reward_sent_at'] = $extra['reward_sent_at'] ?? now();
        }

        DB::transaction(function () use ($unlock, $from, $to, $actor, $extra) {
            $unlock->forceFill([
                'reward_status' => $to->value,
                'reward_sent_at' => $to === SalesAchievementRewardStatus::Sent
                    ? ($extra['reward_sent_at'] ?? now())
                    : $unlock->reward_sent_at,
                'reward_carrier' => array_key_exists('reward_carrier', $extra)
                    ? $extra['reward_carrier']
                    : $unlock->reward_carrier,
                'reward_tracking_code' => array_key_exists('reward_tracking_code', $extra)
                    ? $extra['reward_tracking_code']
                    : $unlock->reward_tracking_code,
                'reward_admin_notes' => array_key_exists('reward_admin_notes', $extra)
                    ? $extra['reward_admin_notes']
                    : $unlock->reward_admin_notes,
            ])->save();

            SalesAchievementRewardStatusHistory::query()->create([
                'sales_achievement_unlock_id' => $unlock->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'changed_by' => $actor->id,
                'note' => $extra['note'] ?? null,
                'tracking_code' => $extra['reward_tracking_code'] ?? $unlock->reward_tracking_code,
                'carrier' => $extra['reward_carrier'] ?? $unlock->reward_carrier,
            ]);
        });

        $action = $to === SalesAchievementRewardStatus::Sent
            ? 'achievement.reward_sent'
            : 'achievement.reward_status_updated';

        PlatformAuditService::log($action, [
            'unlock_id' => $unlock->id,
            'tenant_id' => $unlock->tenant_id,
            'from' => $from->value,
            'to' => $to->value,
            'note' => $extra['note'] ?? null,
            'tracking_code' => $unlock->fresh()->reward_tracking_code,
        ], $request);

        if (in_array($to, [SalesAchievementRewardStatus::InProduction, SalesAchievementRewardStatus::Sent], true)) {
            try {
                NotifySalesAchievementRewardStatusJob::dispatch($unlock->id);
            } catch (\Throwable $e) {
                Log::warning('SalesAchievementRewardStatusService: falha ao notificar', [
                    'unlock_id' => $unlock->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $unlock->fresh();
    }
}
