<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\Versell\VersellWithdrawalReconcileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consulta status do Cash Out Versell até paid/failed (fallback ao webhook).
 */
class ReconcileVersellWithdrawalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MAX_ATTEMPTS = 30;

    public const RELEASE_SECONDS = 120;

    public int $timeout = 90;

    public function __construct(public int $withdrawalId) {}

    public function handle(?VersellWithdrawalReconcileService $reconcile = null): void
    {
        $reconcile ??= app(VersellWithdrawalReconcileService::class);

        $withdrawal = Withdrawal::query()->find($this->withdrawalId);
        if ($withdrawal === null
            || ! in_array($withdrawal->status, ['pending', 'processing'], true)
            || $withdrawal->payout_provider !== 'versell') {
            return;
        }

        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $hasId = trim((string) $withdrawal->payout_external_id) !== ''
            || trim((string) ($meta['idempotency_key'] ?? '')) !== '';
        if (! $hasId) {
            return;
        }

        $outcome = $reconcile->reconcile($withdrawal);
        $this->recordAttemptMeta($withdrawal->fresh() ?? $withdrawal, $outcome['api_status'] ?? null);

        if (($outcome['result'] ?? null) === 'paid' || ($outcome['result'] ?? null) === 'failed') {
            return;
        }

        $this->maybeReleaseForRetry($outcome['api_status'] ?? null);
    }

    private function recordAttemptMeta(Withdrawal $withdrawal, ?string $apiStatus): void
    {
        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta['reconcile_attempt'] = $this->attempts();
        $meta['reconcile_last_at'] = now()->toIso8601String();
        $meta['reconcile_last_api_status'] = $apiStatus;
        $withdrawal->update(['payout_meta' => $meta]);
    }

    private function maybeReleaseForRetry(?string $apiStatus): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            $withdrawal = Withdrawal::query()->find($this->withdrawalId);
            if ($withdrawal === null || ! in_array($withdrawal->status, ['pending', 'processing'], true)) {
                return;
            }

            if ($apiStatus === 'failed') {
                MerchantWithdrawalService::markFailed(
                    $withdrawal->fresh(),
                    'Payout Versell falhou na API (reconciliação).'
                );

                return;
            }

            $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
            $meta['reconcile_exhausted'] = true;
            $meta['reconcile_exhausted_at'] = now()->toIso8601String();
            $meta['reconcile_exhausted_api_status'] = $apiStatus;
            $withdrawal->update(['payout_meta' => $meta]);

            Log::warning('ReconcileVersellWithdrawalJob: tentativas esgotadas sem confirmação definitiva', [
                'withdrawal_id' => $this->withdrawalId,
                'api_status' => $apiStatus,
            ]);

            return;
        }

        $this->release(self::RELEASE_SECONDS);
    }
}
