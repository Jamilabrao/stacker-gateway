<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\Api\ApiWithdrawalWebhookService;
use App\Services\WithdrawalAutoPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWithdrawalPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $withdrawalId,
    ) {
        $this->onQueue((string) config('queue.payouts_queue', 'payouts'));
    }

    public function handle(
        WithdrawalAutoPayoutService $autoPayout,
        ApiWithdrawalWebhookService $webhooks,
    ): void {
        $withdrawal = Withdrawal::query()->find($this->withdrawalId);
        if (! $withdrawal || $withdrawal->status !== 'pending') {
            return;
        }

        // Já aceito pelo provedor: não reenviar (risco de PIX duplicado)
        if (trim((string) $withdrawal->payout_external_id) !== '') {
            return;
        }

        $result = $autoPayout->attemptAutoPayout($withdrawal);

        if (($result['skipped'] ?? false) && ($result['reason'] ?? '') === 'auto_withdrawal_disabled') {
            return;
        }

        if (! ($result['ok'] ?? false) && ! ($result['pending'] ?? false)) {
            Log::warning('ProcessWithdrawalPayoutJob payout not sent', [
                'withdrawal_id' => $withdrawal->id,
                'result' => $result,
            ]);
        }
    }
}
