<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\Versell\VersellPayoutStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks Cash Out Versell (transfer = mudança de status; cashout = falhas de envio).
 */
class VersellPayoutWebhookController extends Controller
{
    /**
     * POST /webhooks/gateways/versell/transfer
     * POST /webhooks/gateways/versell/cashout
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        if ($payload === []) {
            $raw = $request->getContent();
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $payload = is_array($decoded) ? $decoded : [];
        }

        if ($payload === []) {
            return response()->json(['message' => 'empty body'], 400);
        }

        $status = VersellPayoutStatuses::statusFromPayload($payload);
        $endToEndId = VersellPayoutStatuses::endToEndIdFromPayload($payload);
        $idempotencyKey = VersellPayoutStatuses::idempotencyKeyFromPayload($payload);

        $withdrawal = $this->findWithdrawal($endToEndId, $idempotencyKey);
        if ($withdrawal === null) {
            Log::info('VersellPayoutWebhook: saque não encontrado', [
                'gateway' => 'versell',
                'has_e2e' => $endToEndId !== '',
                'has_idempotency' => $idempotencyKey !== '',
                'status' => $status,
                'route' => $request->path(),
            ]);

            return response()->json(['message' => 'ok']);
        }

        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta['webhook_last_status'] = $status !== '' ? $status : null;
        $meta['webhook_last_at'] = now()->toIso8601String();
        $meta['webhook_route'] = $request->path();
        if ($endToEndId !== '' && trim((string) $withdrawal->payout_external_id) !== $endToEndId) {
            $withdrawal->payout_external_id = $endToEndId;
        }
        if ($idempotencyKey !== '' && empty($meta['idempotency_key'])) {
            $meta['idempotency_key'] = $idempotencyKey;
        }
        $withdrawal->update([
            'payout_external_id' => $withdrawal->payout_external_id,
            'payout_meta' => array_filter($meta, fn ($v) => $v !== null && $v !== ''),
        ]);

        $mapped = VersellPayoutStatuses::mapToLocal($status !== '' ? $status : null);
        $isCashoutRoute = str_contains($request->path(), '/cashout');

        // Endpoint cashout = falhas de envio
        if ($isCashoutRoute && $mapped !== 'paid') {
            if (in_array($withdrawal->status, ['pending', 'processing'], true)) {
                MerchantWithdrawalService::markFailed(
                    $withdrawal->fresh(),
                    'Payout Versell falhou (webhook cashout).'
                );
            }

            return response()->json(['message' => 'ok']);
        }

        if ($mapped === 'failed' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout Versell falhou (webhook transfer).'
            );

            return response()->json(['message' => 'ok']);
        }

        if ($mapped === 'paid' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());
        }

        return response()->json(['message' => 'ok']);
    }

    private function findWithdrawal(string $endToEndId, string $idempotencyKey): ?Withdrawal
    {
        if ($endToEndId !== '') {
            $byExt = Withdrawal::query()
                ->where('payout_provider', 'versell')
                ->where('payout_external_id', $endToEndId)
                ->first();
            if ($byExt !== null) {
                return $byExt;
            }
        }

        if ($idempotencyKey !== '') {
            return Withdrawal::query()
                ->where('payout_provider', 'versell')
                ->where('payout_meta->idempotency_key', $idempotencyKey)
                ->first();
        }

        return null;
    }
}
