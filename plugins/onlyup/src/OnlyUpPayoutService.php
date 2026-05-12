<?php

namespace Plugins\OnlyUp;

use App\Models\GatewayCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\EffectiveMerchantFees;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Payout\PayoutUserSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OnlyUpPayoutService
{
    /**
     * @return array{ok: bool, pending?: bool, transaction_id?: string|null, error?: string}
     */
    public function sendWithdrawalToPix(Withdrawal $withdrawal, User $owner): array
    {
        $credential = GatewayCredential::resolveForPayment(null, 'onlyup');
        if ($credential === null) {
            return ['ok' => false, 'error' => 'Saque automático não configurado pela plataforma (OnlyUp).'];
        }
        $credentials = $credential->getDecryptedCredentials();

        $net = (float) $withdrawal->net_amount;
        if ($net <= 0) {
            return ['ok' => false, 'error' => 'Valor líquido do saque inválido.'];
        }

        $economics = GatewayPayoutEconomics::fromCredentialsArray('onlyup', $credentials);
        $requiredNet = $economics['required_min_net'];
        $minCents = (int) max(1, (int) round($requiredNet * 100));
        $apiAmount = GatewayPayoutEconomics::transferAmountBrlForApi($net, $economics['admin_fee_payout_brl']);
        $amountCents = (int) round($net * 100);
        if ($amountCents < $minCents) {
            $tenantId = (int) $withdrawal->tenant_id;
            $minGross = EffectiveMerchantFees::minimumWithdrawalGrossForTargetNet($tenantId, $requiredNet);
            $msg = $minGross !== null
                ? 'O valor mínimo do saque é R$ '
                    .number_format($minGross, 2, ',', '.').' (valor total a solicitar).'
                : 'O valor solicitado é inferior ao mínimo permitido.';

            return ['ok' => false, 'error' => $msg];
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $pixKey = PayoutUserSettings::pixKey($settings);
        $receiverDocument = isset($settings['receiver_document']) ? preg_replace('/\D/', '', (string) $settings['receiver_document']) : '';
        if ($pixKey === '' || $receiverDocument === '') {
            return ['ok' => false, 'error' => 'Complete chave PIX e documento do recebedor no Financeiro antes do saque.'];
        }

        $idempotencyKey = 'getfy-wd-'.$withdrawal->id.'-'.Str::uuid()->toString();

        try {
            $token = OnlyUpHttp::getCashOutAccessToken($credentials);
            $client = OnlyUpHttp::cashOutClient($credentials)
                ->withToken($token)
                ->withHeaders(['x-idempotency-key' => $idempotencyKey]);

            $response = $client->post('/api/v2/pix/payments/dict', [
                'pixKey' => $pixKey,
                'creditorDocument' => $receiverDocument,
                'priority' => 'HIGH',
                'description' => 'Saque #'.$withdrawal->id,
                'expiration' => 600,
                'payment' => [
                    'currency' => 'BRL',
                    'amount' => number_format($apiAmount, 2, '.', ''),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('OnlyUp payout request failed', ['withdrawal_id' => $withdrawal->id, 'message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'OnlyUp: '.$e->getMessage()];
        }

        if ($response->status() !== 202 && ! $response->successful()) {
            $detail = (string) ($response->json('detail') ?? $response->json('title') ?? 'Erro na API OnlyUp.');

            return ['ok' => false, 'error' => 'OnlyUp: '.$detail];
        }

        return [
            'ok' => true,
            'pending' => true,
            'transaction_id' => $idempotencyKey,
        ];
    }
}
