<?php

namespace App\Services;

use App\Models\GatewayCredential;
use App\Models\User;
use App\Jobs\ReconcileCajuPayWithdrawalJob;
use App\Jobs\ReconcileSpacepagWithdrawalJob;
use App\Jobs\ReconcileWooviWithdrawalJob;
use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Services\Payout\PayoutUserSettings;
use App\Services\Payout\PlatformPayoutGateway;
use App\Services\Spacepag\SpacepagPayoutService;
use App\Services\Woovi\WooviPayoutService;
use Plugins\OnlyUp\OnlyUpPayoutService;
use Plugins\OnlyUp\ReconcileOnlyUpWithdrawalJob;

/**
 * Envia saque ao provedor PIX configurado (CajuPay, Spacepag, Woovi ou OnlyUp) após solicitação do infoprodutor.
 */
class WithdrawalAutoPayoutService
{
    /**
     * @return array{ok: bool, skipped?: bool, reason?: string, error?: string, pending?: bool}
     */
    public function attemptAutoPayout(Withdrawal $withdrawal): array
    {
        $slug = PlatformPayoutGateway::activeSlug();
        if ($slug === 'cajupay') {
            return $this->attemptCajuPay($withdrawal);
        }
        if ($slug === 'spacepag') {
            return $this->attemptSpacepag($withdrawal);
        }
        if ($slug === 'woovi') {
            return $this->attemptWoovi($withdrawal);
        }
        if ($slug === 'onlyup') {
            return $this->attemptOnlyUp($withdrawal);
        }

        return ['ok' => false, 'skipped' => true, 'reason' => 'no_payout_gateway'];
    }

    /**
     * @return array{ok: bool, skipped?: bool, reason?: string, error?: string, pending?: bool}
     */
    public function attemptCajuPay(Withdrawal $withdrawal): array
    {
        if ($withdrawal->status !== 'pending') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_pending'];
        }

        $cred = GatewayCredential::resolveForPayment(null, 'cajupay');
        if ($cred === null || ! $cred->is_connected) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'cajupay_not_configured'];
        }

        $tenantId = (int) $withdrawal->tenant_id;
        $owner = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($owner === null) {
            $owner = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
        }

        $settings = is_array($owner?->payout_settings) ? $owner->payout_settings : [];
        $pixKey = PayoutUserSettings::cajuPixKey($settings);
        $pixKeyType = PayoutUserSettings::cajuPixKeyType($settings);
        // O documento do titular vem do cadastro da chave PIX, sem comparação com documento do perfil.
        $keyOwnerDocument = PayoutUserSettings::cajuPixOwnerDocument($settings);
        if ($pixKey === '' || $pixKeyType === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_pix_key'];
        }
        if ($keyOwnerDocument === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_key_owner_document'];
        }

        $payout = new CajuPayPayoutService;
        $result = $payout->sendWithdrawalToPixKey(
            $withdrawal->fresh(),
            null,
            $pixKey,
            $pixKeyType,
            $keyOwnerDocument
        );

        if ($result['ok'] ?? false) {
            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'cajupay',
                'payout_external_id' => $result['external_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => $result['status'] ?? null,
                    'requested_at' => now()->toIso8601String(),
                    'auto' => true,
                ]),
            ]);

            ReconcileCajuPayWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addMinutes(2));

            return ['ok' => true, 'pending' => true];
        }

        $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $errorCode = $result['cajupay_error_code'] ?? null;
        $meta = [
            'last_error' => $result['error'] ?? 'Erro desconhecido',
            'last_attempt_at' => now()->toIso8601String(),
            'auto' => true,
        ];
        if ($errorCode === 'insufficient_funds') {
            $meta['cajupay_error_code'] = 'insufficient_funds';
        }
        $withdrawal->update([
            'payout_provider' => 'cajupay',
            'payout_meta' => $prev + $meta,
        ]);

        if ($errorCode === 'insufficient_funds') {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'cajupay_insufficient_funds',
            ];
        }

        return [
            'ok' => false,
            'skipped' => false,
            'error' => $result['error'] ?? 'Falha ao enviar o saque via PIX.',
        ];
    }

    /**
     * Spacepag retorna pending no HTTP; conclusão em payment.paid (webhook).
     *
     * @return array{ok: bool, skipped?: bool, reason?: string, error?: string, pending?: bool}
     */
    public function attemptSpacepag(Withdrawal $withdrawal): array
    {
        if ($withdrawal->status !== 'pending') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_pending'];
        }

        $cred = GatewayCredential::resolveForPayment(null, 'spacepag');
        if ($cred === null || ! $cred->is_connected) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'spacepag_not_configured'];
        }

        $tenantId = (int) $withdrawal->tenant_id;
        $owner = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($owner === null) {
            $owner = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
        }
        if ($owner === null) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_owner'];
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $pixKey = PayoutUserSettings::pixKey($settings);
        if ($pixKey === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_pix_key'];
        }

        $payout = new SpacepagPayoutService;
        $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

        if ($result['ok'] ?? false) {
            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'spacepag',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                    'auto' => true,
                ]),
            ]);

            ReconcileSpacepagWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            return ['ok' => true, 'pending' => true];
        }

        $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $withdrawal->update([
            'payout_provider' => 'spacepag',
            'payout_meta' => $prev + [
                'last_error' => $result['error'] ?? 'Erro desconhecido',
                'last_attempt_at' => now()->toIso8601String(),
                'auto' => true,
            ],
        ]);

        return [
            'ok' => false,
            'skipped' => false,
            'error' => $result['error'] ?? 'Falha ao enviar o saque via PIX.',
        ];
    }

    /**
     * Woovi retorna pending no HTTP; conclusão via consulta de transação ou cron.
     *
     * @return array{ok: bool, skipped?: bool, reason?: string, error?: string, pending?: bool}
     */
    public function attemptWoovi(Withdrawal $withdrawal): array
    {
        if ($withdrawal->status !== 'pending') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_pending'];
        }

        $cred = GatewayCredential::resolveForPayment(null, 'woovi');
        if ($cred === null || ! $cred->is_connected) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'woovi_not_configured'];
        }

        $tenantId = (int) $withdrawal->tenant_id;
        $owner = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($owner === null) {
            $owner = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
        }
        if ($owner === null) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_owner'];
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $pixKey = PayoutUserSettings::pixKey($settings);
        if ($pixKey === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_pix_key'];
        }

        $payout = new WooviPayoutService;
        $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

        if ($result['ok'] ?? false) {
            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'woovi',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                    'auto' => true,
                ]),
            ]);

            ReconcileWooviWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            return ['ok' => true, 'pending' => true];
        }

        $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $withdrawal->update([
            'payout_provider' => 'woovi',
            'payout_meta' => $prev + [
                'last_error' => $result['error'] ?? 'Erro desconhecido',
                'last_attempt_at' => now()->toIso8601String(),
                'auto' => true,
            ],
        ]);

        return [
            'ok' => false,
            'skipped' => false,
            'error' => $result['error'] ?? 'Falha ao enviar o saque via PIX.',
        ];
    }

    /**
     * OnlyUp retorna 202; conclusão via webhook ou job de reconciliação.
     *
     * @return array{ok: bool, skipped?: bool, reason?: string, error?: string, pending?: bool}
     */
    public function attemptOnlyUp(Withdrawal $withdrawal): array
    {
        if ($withdrawal->status !== 'pending') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_pending'];
        }

        $cred = GatewayCredential::resolveForPayment(null, 'onlyup');
        if ($cred === null || ! $cred->is_connected) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'onlyup_not_configured'];
        }

        $tenantId = (int) $withdrawal->tenant_id;
        $owner = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($owner === null) {
            $owner = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
        }
        if ($owner === null) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_owner'];
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $pixKey = PayoutUserSettings::pixKey($settings);
        if ($pixKey === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_pix_key'];
        }

        $payout = new OnlyUpPayoutService;
        $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

        if ($result['ok'] ?? false) {
            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'onlyup',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                    'auto' => true,
                ]),
            ]);

            ReconcileOnlyUpWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            return ['ok' => true, 'pending' => true];
        }

        $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $withdrawal->update([
            'payout_provider' => 'onlyup',
            'payout_meta' => $prev + [
                'last_error' => $result['error'] ?? 'Erro desconhecido',
                'last_attempt_at' => now()->toIso8601String(),
                'auto' => true,
            ],
        ]);

        return [
            'ok' => false,
            'skipped' => false,
            'error' => $result['error'] ?? 'Falha ao enviar o saque via PIX.',
        ];
    }
}
