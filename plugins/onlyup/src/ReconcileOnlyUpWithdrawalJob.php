<?php

namespace Plugins\OnlyUp;

use App\Models\GatewayCredential;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consulta GET /api/v2/pix/payments/idempotencyKey/{key} até o saque constar liquidado.
 */
class ReconcileOnlyUpWithdrawalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 90;

    public function __construct(public int $withdrawalId) {}

    public function handle(): void
    {
        $withdrawal = Withdrawal::query()->find($this->withdrawalId);
        if ($withdrawal === null || $withdrawal->status !== 'pending' || $withdrawal->payout_provider !== 'onlyup') {
            return;
        }

        $key = trim((string) $withdrawal->payout_external_id);
        if ($key === '') {
            return;
        }

        $cred = GatewayCredential::resolveForPayment(null, 'onlyup');
        if ($cred === null || ! $cred->is_connected) {
            $this->maybeReleaseForRetry();

            return;
        }

        $credentials = $cred->getDecryptedCredentials();
        if ($credentials === []) {
            return;
        }

        $driver = new OnlyUpDriver;
        try {
            $apiStatus = $driver->getPayoutTransferStatus($key, $credentials);
        } catch (\Throwable $e) {
            Log::warning('ReconcileOnlyUpWithdrawalJob: consulta falhou', [
                'withdrawal_id' => $this->withdrawalId,
                'message' => $e->getMessage(),
            ]);
            $this->maybeReleaseForRetry();

            return;
        }

        if ($apiStatus === 'paid') {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());

            return;
        }

        $this->maybeReleaseForRetry();
    }

    private function maybeReleaseForRetry(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        if ($this->attempts() >= 36) {
            Log::info('ReconcileOnlyUpWithdrawalJob: encerrando tentativas', ['withdrawal_id' => $this->withdrawalId]);

            return;
        }

        $this->release(120);
    }
}
