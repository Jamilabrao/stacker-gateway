<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Services\MerchantWithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consulta status do payout na CajuPay até o saque constar como pago (fallback ao webhook).
 * Intervalo: 2 min entre tentativas; máximo 30 tentativas (~1 h).
 */
class ReconcileCajuPayWithdrawalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MAX_ATTEMPTS = 30;

    public const RELEASE_SECONDS = 120;

    public int $timeout = 90;

    public function __construct(public int $withdrawalId) {}

    public function handle(): void
    {
        $withdrawal = Withdrawal::query()->find($this->withdrawalId);
        if ($withdrawal === null
            || ! in_array($withdrawal->status, ['pending', 'processing'], true)
            || $withdrawal->payout_provider !== 'cajupay') {
            return;
        }

        $externalId = trim((string) $withdrawal->payout_external_id);
        if ($externalId === '') {
            return;
        }

        $apiStatus = null;
        try {
            $apiStatus = app(CajuPayPayoutService::class)->getPayoutSettlementStatus($externalId);
        } catch (\Throwable $e) {
            Log::warning('ReconcileCajuPayWithdrawalJob: falha na consulta', [
                'withdrawal_id' => $this->withdrawalId,
                'message' => $e->getMessage(),
            ]);
            $this->recordReconcileAttempt($withdrawal, null);
            $this->maybeReleaseForRetry();

            return;
        }

        $this->recordReconcileAttempt($withdrawal, $apiStatus);

        if ($apiStatus === 'paid') {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());

            return;
        }

        if ($apiStatus === 'failed') {
            Log::info('ReconcileCajuPayWithdrawalJob: payout com falha na API', [
                'withdrawal_id' => $this->withdrawalId,
                'external_id' => $externalId,
            ]);

            return;
        }

        $this->maybeReleaseForRetry();
    }

    private function recordReconcileAttempt(Withdrawal $withdrawal, ?string $apiStatus): void
    {
        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta['reconcile_last_at'] = now()->toIso8601String();
        $meta['reconcile_last_api_status'] = $apiStatus;
        $meta['reconcile_attempt'] = $this->attempts();
        $withdrawal->update(['payout_meta' => $meta]);
    }

    private function maybeReleaseForRetry(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            Log::info('ReconcileCajuPayWithdrawalJob: encerrando tentativas', [
                'withdrawal_id' => $this->withdrawalId,
            ]);

            return;
        }

        $this->release(self::RELEASE_SECONDS);
    }
}
