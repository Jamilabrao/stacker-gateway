<?php

namespace App\Jobs;

use App\Models\SalesAchievementUnlock;
use App\Services\PanelPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class NotifySalesAchievementEarnedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $unlockId) {}

    public function handle(PanelPushService $panelPush): void
    {
        $unlock = SalesAchievementUnlock::query()->find($this->unlockId);
        if (! $unlock) {
            return;
        }

        $title = 'Nova conquista desbloqueada!';
        $body = 'Parabéns! Você conquistou a insígnia “'.$unlock->name_snapshot.'”.';
        if (filled($unlock->reward_name_snapshot)) {
            $body .= ' Você também garantiu o prêmio “'.$unlock->reward_name_snapshot.'”.';
        }

        try {
            $panelPush->sendAndPersistToTenant(
                (int) $unlock->tenant_id,
                'system',
                $title,
                $body,
                '/conquistas',
                'achievement_earned_'.$unlock->id
            );
        } catch (\Throwable $e) {
            Log::warning('NotifySalesAchievementEarnedJob failed', [
                'unlock_id' => $this->unlockId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
