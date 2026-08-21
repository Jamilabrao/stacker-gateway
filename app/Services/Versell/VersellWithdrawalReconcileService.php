<?php

namespace App\Services\Versell;

use App\Models\GatewayCredential;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Support\Facades\Log;

/**
 * Consulta status do Cash Out Versell e aplica paid/failed no saque local.
 */
class VersellWithdrawalReconcileService
{
    public function __construct(
        private VersellPayoutService $payoutService = new VersellPayoutService(),
    ) {}

    /**
     * @return array{result: 'paid'|'failed'|'pending'|null, message: string, api_status: ?string}
     */
    public function reconcile(Withdrawal $withdrawal): array
    {
        if (! in_array($withdrawal->status, ['pending', 'processing'], true) || $withdrawal->payout_provider !== 'versell') {
            return [
                'result' => null,
                'message' => 'Saque ignorado (não está pending/processing versell).',
                'api_status' => null,
            ];
        }

        $externalId = trim((string) $withdrawal->payout_external_id);
        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $idempotencyKey = trim((string) ($meta['idempotency_key'] ?? ''));

        if ($externalId === '' && $idempotencyKey === '') {
            return [
                'result' => null,
                'message' => 'Saque sem payout_external_id/idempotency_key; não é possível consultar na Versell.',
                'api_status' => null,
            ];
        }

        $credential = GatewayCredential::resolveForPayment(null, 'versell');
        if ($credential === null || ! $credential->is_connected) {
            return [
                'result' => null,
                'message' => 'Versell não configurada.',
                'api_status' => null,
            ];
        }
        $credentials = $credential->getDecryptedCredentials();

        try {
            $lookupId = $externalId !== '' ? $externalId : $idempotencyKey;
            $mapped = $this->payoutService->getPayoutSettlementStatus($lookupId, $credentials);

            // Se external era a idempotency key, tenta enriquecer endToEndId
            if ($externalId === $idempotencyKey || ($mapped === 'pending' && $idempotencyKey !== '')) {
                $found = $this->payoutService->lookupByIdempotencyKey($credentials, $idempotencyKey);
                if ($found !== null) {
                    $newExt = trim((string) ($found['external_id'] ?? ''));
                    if ($newExt !== '' && $newExt !== $externalId) {
                        $withdrawal->update(['payout_external_id' => $newExt]);
                        $externalId = $newExt;
                    }
                    $mapped = VersellPayoutStatuses::mapToLocal($found['status'] ?? null) ?? $mapped;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('VersellWithdrawalReconcileService: falha na consulta', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);

            $this->touchMeta($withdrawal, null, [
                'reconcile_last_error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return [
                'result' => null,
                'message' => 'Falha ao consultar Versell: '.$e->getMessage(),
                'api_status' => null,
            ];
        }

        $this->touchMeta($withdrawal, $mapped);

        if ($mapped === 'paid') {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());

            return [
                'result' => 'paid',
                'message' => 'Saque marcado como pago.',
                'api_status' => $mapped,
            ];
        }

        if ($mapped === 'failed') {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout Versell cancelado ou falhou (reconciliação).'
            );

            return [
                'result' => 'failed',
                'message' => 'Saque marcado como falho.',
                'api_status' => $mapped,
            ];
        }

        return [
            'result' => 'pending',
            'message' => 'Saque ainda pendente na Versell.',
            'api_status' => $mapped,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function touchMeta(Withdrawal $withdrawal, ?string $apiStatus, array $extra = []): void
    {
        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        if ($apiStatus !== null) {
            $meta['versell_status'] = $apiStatus;
            $meta['reconcile_last_api_status'] = $apiStatus;
        }
        $meta['reconcile_last_at'] = now()->toIso8601String();
        foreach ($extra as $k => $v) {
            $meta[$k] = $v;
        }
        $withdrawal->update(['payout_meta' => $meta]);
    }
}
