<?php

namespace App\Services\CajuPay;

use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\WithdrawalPixReceiptService;
use Illuminate\Support\Facades\Log;

/**
 * Consulta status do payout na CajuPay e aplica paid/failed no saque local.
 */
class CajuPayWithdrawalReconcileService
{
    public function __construct(
        private CajuPayPayoutService $payoutService,
        private WithdrawalPixReceiptService $receiptService,
    ) {}

    /**
     * @return array{result: 'paid'|'failed'|'pending'|null, message: string, api_status: ?string}
     */
    public function reconcile(Withdrawal $withdrawal): array
    {
        if (! in_array($withdrawal->status, ['pending', 'processing'], true) || $withdrawal->payout_provider !== 'cajupay') {
            return [
                'result' => null,
                'message' => 'Saque ignorado (não está pending/processing cajupay).',
                'api_status' => null,
            ];
        }

        $externalId = trim((string) $withdrawal->payout_external_id);
        if ($externalId === '') {
            return [
                'result' => null,
                'message' => 'Saque sem payout_external_id; não é possível consultar na CajuPay.',
                'api_status' => null,
            ];
        }

        try {
            $apiStatus = $this->payoutService->getPayoutSettlementStatus($externalId, (int) $withdrawal->tenant_id);
        } catch (\Throwable $e) {
            Log::warning('CajuPayWithdrawalReconcileService: falha na consulta', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);

            $this->touchMeta($withdrawal, null, [
                'reconcile_last_error' => $e->getMessage(),
            ]);

            return [
                'result' => null,
                'message' => 'Falha ao consultar CajuPay: '.$e->getMessage(),
                'api_status' => null,
            ];
        }

        $this->touchMeta($withdrawal, $apiStatus);

        if ($apiStatus === 'paid') {
            $fresh = $withdrawal->fresh();
            $this->receiptService->enrichFromCajuPay($fresh);
            MerchantWithdrawalService::markPaid($fresh);

            return [
                'result' => 'paid',
                'message' => 'Saque marcado como pago.',
                'api_status' => $apiStatus,
            ];
        }

        if ($apiStatus === 'failed') {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout CajuPay cancelado ou falhou (reconciliação).'
            );

            return [
                'result' => 'failed',
                'message' => 'Saque marcado como falho e saldo devolvido.',
                'api_status' => $apiStatus,
            ];
        }

        return [
            'result' => $apiStatus === 'pending' ? 'pending' : null,
            'message' => 'API retornou status: '.($apiStatus ?? 'null').' (esperado paid ou failed/cancelled).',
            'api_status' => $apiStatus,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function touchMeta(Withdrawal $withdrawal, ?string $apiStatus, array $extra = []): void
    {
        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta['reconcile_last_at'] = now()->toIso8601String();
        $meta['reconcile_last_api_status'] = $apiStatus;
        foreach ($extra as $key => $value) {
            $meta[$key] = $value;
        }
        $withdrawal->update(['payout_meta' => $meta]);
    }
}
