<?php

namespace Plugins\OnlyUp;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class OnlyUpWebhookHandler
{
    public function handle(Request $request, string $slug): JsonResponse
    {
        if ($slug !== 'onlyup') {
            return response()->json(['message' => 'Invalid slug'], 404);
        }

        $credential = GatewayCredential::resolveForPayment(null, 'onlyup');
        if ($credential === null || ! $credential->is_connected) {
            return response()->json(['message' => 'Gateway not configured'], 503);
        }

        $credentials = $credential->getDecryptedCredentials();
        $headerName = trim((string) ($credentials['webhook_header_name'] ?? ''));
        $headerToken = (string) ($credentials['webhook_header_token'] ?? '');
        if ($headerName === '' || $headerToken === '') {
            return response()->json(['message' => 'Webhook auth not configured'], 503);
        }

        $incoming = $request->header($headerName);
        if (! is_string($incoming) || ! hash_equals($headerToken, $incoming)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        if ($this->isTransferPayload($payload)) {
            return $this->handleTransferWebhook($payload, $credentials);
        }

        return $this->handlePixChargeWebhook($payload, $credentials);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isTransferPayload(array $payload): bool
    {
        $type = strtoupper((string) ($payload['type'] ?? ''));
        if ($type === 'TRANSFER') {
            return true;
        }
        $data = $payload['data'] ?? null;

        return is_array($data)
            && isset($data['idempotencyKey'], $data['status'])
            && is_string($data['idempotencyKey'])
            && $data['idempotencyKey'] !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $credentials
     */
    private function handleTransferWebhook(array $payload, array $credentials): JsonResponse
    {
        $idempotencyKey = trim((string) data_get($payload, 'data.idempotencyKey', ''));
        if ($idempotencyKey === '') {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $withdrawal = Withdrawal::query()
            ->where('payout_provider', 'onlyup')
            ->where('payout_external_id', $idempotencyKey)
            ->first();

        if ($withdrawal === null) {
            Log::info('OnlyUp transfer webhook: withdrawal not found', ['key' => $idempotencyKey]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        $driver = new OnlyUpDriver;
        $api = $driver->getPayoutTransferStatus($idempotencyKey, $credentials);
        if ($api === 'paid') {
            if ($withdrawal->status === 'pending') {
                MerchantWithdrawalService::markPaid($withdrawal->fresh());
            }

            return response()->json(['received' => true]);
        }

        if ($api === 'cancelled') {
            $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
            $withdrawal->update([
                'payout_meta' => $prev + [
                    'last_error' => 'Transferência cancelada na OnlyUp.',
                    'last_webhook_at' => now()->toIso8601String(),
                ],
            ]);

            return response()->json(['received' => true]);
        }

        return response()->json(['received' => true, 'pending' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $credentials
     */
    private function handlePixChargeWebhook(array $payload, array $credentials): JsonResponse
    {
        if (isset($payload['pix']) && is_array($payload['pix']) && ! empty($payload['pix']['devolucoes'])) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $txid = null;
        if (isset($payload['txid']) && is_string($payload['txid'])) {
            $txid = trim($payload['txid']);
        }
        if ($txid === null || $txid === '') {
            $pix = $payload['pix'] ?? null;
            if (is_array($pix) && isset($pix['txid']) && is_string($pix['txid'])) {
                $txid = trim($pix['txid']);
            }
        }

        if ($txid === null || $txid === '') {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $driver = new OnlyUpDriver;
        $api = $driver->getTransactionStatus($txid, $credentials);
        if ($api !== 'paid') {
            Log::info('OnlyUp pix webhook: not paid after reconfirm', ['txid' => $txid, 'api' => $api]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        ProcessPaymentWebhook::dispatchSync('onlyup', $txid, 'order.paid', 'paid', $payload);

        return response()->json(['received' => true]);
    }
}
