<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Services\CajuPay\CajuPayPayoutStatuses;
use App\Services\MerchantWithdrawalService;
use App\Services\WithdrawalPixReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class CajuPayPayoutWebhookController extends Controller
{
    /**
     * POST /webhooks/gateways/cajupay/payout
     */
    public function handle(Request $request): Response
    {
        $rawBody = $request->getContent();
        if (! is_string($rawBody) || $rawBody === '') {
            return response('empty body', 400);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response('invalid json', 400);
        }

        $sigHeader = (string) $request->header('X-CajuPay-Signature', '');
        $parsed = $this->parseSignatureHeader($sigHeader);
        $timestampHeader = (string) $request->header('X-CajuPay-Timestamp', '');

        $timestamp = $parsed['t'] ?? (is_numeric($timestampHeader) ? (int) $timestampHeader : 0);
        $signatureHex = strtolower($parsed['v1'] ?? '');

        if ($signatureHex === '' || $timestamp <= 0) {
            return response('invalid_signature_header', 400);
        }

        if (abs(time() - $timestamp) > 300) {
            return response('stale_timestamp', 401);
        }

        $signingSecret = $this->resolveSigningSecret($rawBody, (string) $timestamp, $signatureHex);
        if ($signingSecret === null) {
            Log::warning('CajuPayPayoutWebhook: assinatura inválida.', [
                'event' => (string) ($request->header('X-CajuPay-Event') ?? ($payload['type'] ?? '')),
            ]);

            return response('invalid_signature', 401);
        }

        $eventType = CajuPayPayoutStatuses::eventTypeFromWebhookPayload($payload);
        if ($eventType === '' && is_string($request->header('X-CajuPay-Event'))) {
            $eventType = strtolower(trim($request->header('X-CajuPay-Event')));
        }
        $status = CajuPayPayoutStatuses::statusFromWebhookPayload($payload);
        $externalId = CajuPayPayoutStatuses::externalIdFromWebhookPayload($payload);

        if ($externalId === '') {
            Log::info('CajuPayPayoutWebhook: payout sem external id.', [
                'event' => $eventType,
                'status' => $status,
                'payload_keys' => array_keys($payload),
            ]);

            return response('ok', 200);
        }

        $withdrawal = Withdrawal::query()
            ->where('payout_provider', 'cajupay')
            ->where('payout_external_id', $externalId)
            ->first();

        if ($withdrawal === null) {
            Log::info('CajuPayPayoutWebhook: saque não encontrado.', [
                'external_id' => $externalId,
                'event' => $eventType,
                'status' => $status,
            ]);

            return response('ok', 200);
        }

        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta['webhook_last_event'] = $eventType !== '' ? $eventType : null;
        $meta['webhook_last_status'] = $status !== '' ? $status : null;
        $meta['webhook_last_at'] = now()->toIso8601String();
        $withdrawal->update([
            'payout_meta' => array_filter($meta, fn ($value) => $value !== null && $value !== ''),
        ]);

        if (CajuPayPayoutStatuses::isFailedConfirmation($eventType, $status)) {
            $object = data_get($payload, 'data.object');
            $cancelMessage = is_array($object)
                ? trim((string) ($object['cancel_message'] ?? $object['last_error'] ?? ''))
                : '';
            $reason = CajuPayPayoutStatuses::isFailedEvent($eventType) && $status === ''
                ? 'Payout CajuPay falhou (webhook): '.$eventType
                : 'Payout CajuPay falhou (webhook): '.($cancelMessage !== '' ? $cancelMessage : ($status !== '' ? $status : $eventType));

            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                $reason
            );

            return response('ok', 200);
        }

        if (! CajuPayPayoutStatuses::isPaidConfirmation($eventType, $status)) {
            return response('ok', 200);
        }

        $receiptPayload = data_get($payload, 'data.object');
        if (! is_array($receiptPayload)) {
            $receiptPayload = $payload['data'] ?? $payload;
        }
        if (is_array($receiptPayload)) {
            app(WithdrawalPixReceiptService::class)->persistReceiptFromPayload($withdrawal->fresh(), $receiptPayload);
        }

        MerchantWithdrawalService::markPaid($withdrawal->fresh());

        return response('ok', 200);
    }

    /**
     * @return array{t?: string, v1?: string}
     */
    private function parseSignatureHeader(string $header): array
    {
        $out = [];
        $header = trim($header);
        if ($header === '') {
            return $out;
        }

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $out['t'] = substr($part, 2);
            }
            if (str_starts_with($part, 'v1=')) {
                $out['v1'] = substr($part, 3);
            }
        }

        return $out;
    }

    private function resolveSigningSecret(string $rawBody, string $timestamp, string $signatureHex): ?string
    {
        if ($signatureHex === '' || $timestamp === '') {
            return null;
        }

        $signedPayload = $timestamp.'.'.$rawBody;

        $resolver = app(CajuPayAccountResolver::class);
        $accounts = $resolver->allConnectedForWebhookValidation();

        foreach ($accounts as $account) {
            $secret = $this->matchSigningSecret($account->getDecryptedCredentials(), $signedPayload, $signatureHex);
            if ($secret !== null) {
                return $secret;
            }
        }

        // Fallback legado: GatewayCredential (instalações pré-multi-conta).
        $legacy = GatewayCredential::query()
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->get();

        foreach ($legacy as $credential) {
            $secret = $this->matchSigningSecret($credential->getDecryptedCredentials(), $signedPayload, $signatureHex);
            if ($secret !== null) {
                return $secret;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $creds
     */
    private function matchSigningSecret(array $creds, string $signedPayload, string $signatureHex): ?string
    {
        foreach ([
            'checkout_webhook_signing_secret',
            'webhook_signing_secret',
            'payout_webhook_signing_secret',
            'webhook_secret',
        ] as $key) {
            $secret = trim((string) ($creds[$key] ?? ''));
            if ($secret === '') {
                continue;
            }
            $expected = hash_hmac('sha256', $signedPayload, $secret);
            if (hash_equals($expected, $signatureHex)) {
                return $secret;
            }
        }

        return null;
    }
}

