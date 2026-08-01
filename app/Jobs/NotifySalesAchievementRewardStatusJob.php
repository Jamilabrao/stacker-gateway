<?php

namespace App\Jobs;

use App\Enums\SalesAchievementRewardStatus;
use App\Models\SalesAchievementUnlock;
use App\Services\PanelPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class NotifySalesAchievementRewardStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $unlockId) {}

    public function handle(PanelPushService $panelPush): void
    {
        $unlock = SalesAchievementUnlock::query()->find($this->unlockId);
        if (! $unlock || ! $unlock->hasReward()) {
            return;
        }

        $status = $unlock->rewardStatusEnum();
        $title = match ($status) {
            SalesAchievementRewardStatus::InProduction => 'Seu prêmio entrou em produção',
            SalesAchievementRewardStatus::Sent => 'Seu prêmio foi enviado',
            default => null,
        };
        if ($title === null) {
            return;
        }

        $body = 'Premiação “'.($unlock->reward_name_snapshot ?: $unlock->name_snapshot).'”: '.$status->label().'.';
        if ($status === SalesAchievementRewardStatus::Sent && filled($unlock->reward_tracking_code)) {
            $body .= ' Código de rastreio: '.$unlock->reward_tracking_code.'.';
        }

        try {
            $panelPush->sendAndPersistToTenant(
                (int) $unlock->tenant_id,
                'system',
                $title,
                $body,
                '/conquistas',
                'achievement_reward_'.$unlock->id.'_'.$status->value
            );
        } catch (\Throwable $e) {
            Log::warning('NotifySalesAchievementRewardStatusJob failed', [
                'unlock_id' => $this->unlockId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
