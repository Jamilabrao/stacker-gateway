<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\ProvidesPlatformGatewayProps;
use App\Http\Controllers\Controller;
use App\Jobs\ReconcileCajuPayWithdrawalJob;
use App\Jobs\ReconcileSpacepagWithdrawalJob;
use App\Jobs\ReconcileWooviWithdrawalJob;
use Plugins\OnlyUp\OnlyUpPayoutService;
use Plugins\OnlyUp\ReconcileOnlyUpWithdrawalJob;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Services\Spacepag\SpacepagPayoutService;
use App\Services\Woovi\WooviPayoutService;
use App\Services\EffectiveMerchantFees;
use App\Support\PercentDecimal;
use App\Services\EffectiveSettlementRules;
use App\Services\ApiPixAccess;
use App\Services\MerchantWithdrawalService;
use App\Services\Payout\PayoutUserSettings;
use App\Services\Payout\PlatformPayoutGateway;
use App\Services\PlatformAuditService;
use App\Services\PlatformPaymentMethods;
use App\Models\Setting;
use App\Support\PlatformConfigContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialController extends Controller
{
    use ProvidesPlatformGatewayProps;

    public function index(): Response
    {
        $tenantId = PlatformConfigContext::settingsTenantId();

        return Inertia::render('Platform/Financial/Index', [
            'gateways' => $this->buildGatewaysListForSettings($tenantId),
            'gateway_order' => $this->buildGatewayOrderForSettings($tenantId),
            'merchant_fee_rules' => EffectiveMerchantFees::platformDefaults(),
            'merchant_settlement_rules' => EffectiveSettlementRules::platformDefaults(),
            'api_pix_enabled' => ApiPixAccess::globalEnabled(),
            'payout_gateway_preference' => PlatformPayoutGateway::preference(),
            'payout_gateway_active' => PlatformPayoutGateway::activeSlug(),
            'gateway_webhook_security_warnings' => $this->gatewayWebhookSecurityWarnings($tenantId),
            'platform_payment_methods_enabled' => PlatformPaymentMethods::platformEnabled(),
            'platform_payment_method_labels' => PlatformPaymentMethods::labelsForAdmin(),
        ]);
    }

    public function updatePaymentMethods(Request $request): RedirectResponse
    {
        $rules = [
            'platform_payment_methods_enabled' => ['required', 'array'],
        ];
        foreach (PlatformPaymentMethods::METHOD_KEYS as $key) {
            $rules["platform_payment_methods_enabled.$key"] = ['nullable', 'boolean'];
        }
        $request->validate($rules);

        $out = PlatformPaymentMethods::defaults();
        foreach (PlatformPaymentMethods::METHOD_KEYS as $key) {
            $out[$key] = $request->boolean("platform_payment_methods_enabled.$key", true);
        }

        if (! collect($out)->contains(true)) {
            return redirect()->route('plataforma.financeiro.index', ['tab' => 'metodos'])
                ->with('error', 'Ative pelo menos uma forma de pagamento na plataforma.');
        }

        Setting::set('platform_payment_methods_enabled', $out, null);

        PlatformAuditService::log('platform.financial.payment_methods_updated', ['enabled' => $out], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'metodos'])
            ->with('success', 'Formas de pagamento da plataforma atualizadas.');
    }

    /**
     * Preferência de saque automático (CajuPay, Spacepag, Woovi ou automático).
     */
    public function updatePayoutGatewayPreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preference' => ['required', 'string', 'in:auto,cajupay,spacepag,woovi,onlyup'],
        ]);

        $pref = $validated['preference'];
        Setting::set('platform_payout_gateway', $pref === 'auto' ? null : $pref, null);

        PlatformAuditService::log('platform.financial.payout_gateway_preference', ['preference' => $pref], $request);

        return response()->json([
            'success' => true,
            'message' => 'Preferência de saque automático atualizada.',
            'payout_gateway_preference' => PlatformPayoutGateway::preference(),
            'payout_gateway_active' => PlatformPayoutGateway::activeSlug(),
        ]);
    }

    public function updateSettlement(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'merchant_settlement_rules' => ['required', 'array'],
        ]);
        foreach (EffectiveSettlementRules::SETTLEMENT_METHOD_KEYS as $key) {
            $request->validate([
                "merchant_settlement_rules.$key" => ['nullable', 'array'],
                "merchant_settlement_rules.$key.days_to_available" => ['nullable', 'integer', 'min:0', 'max:365'],
                "merchant_settlement_rules.$key.reserve_percent" => ['nullable', 'numeric', 'min:0', 'max:100'],
                "merchant_settlement_rules.$key.reserve_hold_days" => ['nullable', 'integer', 'min:0', 'max:365'],
            ]);
        }

        $out = [];
        foreach (EffectiveSettlementRules::SETTLEMENT_METHOD_KEYS as $key) {
            $block = $validated['merchant_settlement_rules'][$key] ?? [];
            $out[$key] = [
                'days_to_available' => max(0, (int) ($block['days_to_available'] ?? 0)),
                'reserve_percent' => round(min(100, max(0, (float) ($block['reserve_percent'] ?? 0))), 2),
                'reserve_hold_days' => max(0, min(365, (int) ($block['reserve_hold_days'] ?? 0))),
            ];
        }

        Setting::set('merchant_settlement_rules', $out, null);

        PlatformAuditService::log('platform.financial.settlement_updated', ['rules' => $out], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'liquidacao'])
            ->with('success', 'Regras de liquidação atualizadas.');
    }

    public function updateFees(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'merchant_fee_rules' => ['required', 'array'],
            'api_pix_enabled' => ['nullable', 'boolean'],
        ]);
        $rules = ['pix', 'api_pix', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'];
        foreach ($rules as $key) {
            $request->validate([
                "merchant_fee_rules.$key" => ['nullable', 'array'],
                "merchant_fee_rules.$key.percent" => ['nullable', 'numeric', 'min:0', 'max:100'],
                "merchant_fee_rules.$key.fixed" => ['nullable', 'numeric', 'min:0', 'max:999999'],
            ]);
        }

        $out = [];
        foreach ($rules as $key) {
            $block = $validated['merchant_fee_rules'][$key] ?? [];
            $out[$key] = [
                'percent' => PercentDecimal::toFloat(PercentDecimal::normalize($block['percent'] ?? 0)),
                'fixed' => round((float) ($block['fixed'] ?? 0), 2),
            ];
        }

        Setting::set('merchant_fee_rules', $out, null);
        Setting::set('api_pix_enabled', (bool) ($validated['api_pix_enabled'] ?? ApiPixAccess::globalEnabled()), null);

        PlatformAuditService::log('platform.financial.fees_updated', [
            'rules' => $out,
            'api_pix_enabled' => (bool) ($validated['api_pix_enabled'] ?? ApiPixAccess::globalEnabled()),
        ], $request);

        return redirect()->route('plataforma.financeiro.index', ['tab' => 'taxas'])
            ->with('success', 'Taxas da plataforma atualizadas.');
    }

    public function approveWithdrawal(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $locked = MerchantWithdrawalService::beginPayoutApproval((int) $withdrawal->id);
        if ($locked === null) {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Este saque não está pendente ou já está em processamento.');
        }

        $withdrawal = $locked;

        $validated = $request->validate([
            'payout_manual' => ['nullable', 'boolean'],
        ]);
        $manual = (bool) ($validated['payout_manual'] ?? false);

        if ($manual) {
            $withdrawal->update([
                'payout_manual' => true,
                'payout_provider' => 'manual',
            ]);
            MerchantWithdrawalService::markPaid($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'manual' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque marcado como pago (aprovado manualmente, sem API).');
        }

        $slug = PlatformPayoutGateway::activeSlug();
        if ($slug === null) {
            MerchantWithdrawalService::releasePayoutApproval($withdrawal);

            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Nenhum gateway de payout PIX está conectado. Use aprovação manual ou configure Integrações > Gateways.');
        }

        $owner = $this->withdrawalTenantOwner($withdrawal);

        if ($owner === null) {
            MerchantWithdrawalService::releasePayoutApproval($withdrawal);

            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Titular da conta (infoprodutor) não encontrado.');
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];

        if ($slug === 'cajupay') {
            $result = $this->sendCajuPayWithdrawalPayout($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $this->recordCajuPayWithdrawalFailure($withdrawal, $result);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

                return redirect()->route('plataforma.saques.index')
                    ->with('error', $result['error'] ?? 'Falha ao enviar o saque.');
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'cajupay',
                'payout_external_id' => $result['external_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => $result['status'] ?? null,
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileCajuPayWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addMinutes(2));

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'cajupay' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado via CajuPay. Aguardando confirmação para marcar como pago.');
        }

        if ($slug === 'spacepag') {
            $payout = new SpacepagPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'spacepag',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'Spacepag: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'spacepag',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileSpacepagWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'spacepag' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à Spacepag. Será marcado como pago após confirmação do PIX (webhook).');
        }

        if ($slug === 'woovi') {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                MerchantWithdrawalService::releasePayoutApproval($withdrawal);

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).');
            }

            $payout = new WooviPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'woovi',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'Woovi: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'woovi',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileWooviWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'woovi' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à Woovi. Será marcado como pago após confirmação na API.');
        }

        if ($slug === 'onlyup') {
            $pixKey = PayoutUserSettings::pixKey($settings);
            if ($pixKey === '') {
                MerchantWithdrawalService::releasePayoutApproval($withdrawal);

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).');
            }

            $payout = new OnlyUpPayoutService;
            $result = $payout->sendWithdrawalToPix($withdrawal->fresh(), $owner);

            if (! ($result['ok'] ?? false)) {
                $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
                $withdrawal->update([
                    'payout_provider' => 'onlyup',
                    'payout_meta' => $prev + [
                        'last_error' => $result['error'] ?? 'Erro desconhecido',
                        'last_attempt_at' => now()->toIso8601String(),
                    ],
                ]);
                MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

                return redirect()->route('plataforma.saques.index')
                    ->with('error', 'OnlyUp: '.($result['error'] ?? 'Falha ao enviar o saque.'));
            }

            $withdrawal->update([
                'payout_manual' => false,
                'payout_provider' => 'onlyup',
                'payout_external_id' => $result['transaction_id'] ?? null,
                'payout_meta' => array_filter([
                    'api_status' => 'pending',
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);

            ReconcileOnlyUpWithdrawalJob::dispatch($withdrawal->fresh()->id)
                ->delay(now()->addSeconds(90));

            PlatformAuditService::log('platform.withdrawal.approved', ['withdrawal_id' => $withdrawal->id, 'onlyup' => true, 'pending' => true], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('success', 'Saque enviado à OnlyUp. Será marcado como pago após confirmação na API.');
        }

        MerchantWithdrawalService::releasePayoutApproval($withdrawal);

        return redirect()->route('plataforma.saques.index')
            ->with('error', 'Gateway de payout não suportado.');
    }

    /**
     * Nova tentativa de envio PIX via CajuPay para saque ainda pendente (ex.: saldo insuficiente antes).
     */
    public function retryCajuPayWithdrawal(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $locked = MerchantWithdrawalService::beginPayoutApproval((int) $withdrawal->id);
        if ($locked === null) {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Este saque não está pendente ou já está em processamento.');
        }

        $withdrawal = $locked;

        if (PlatformPayoutGateway::activeSlug() !== 'cajupay') {
            MerchantWithdrawalService::releasePayoutApproval($withdrawal);

            return redirect()->route('plataforma.saques.index')
                ->with('error', 'O reprocessamento automático só está disponível com CajuPay como gateway de saque ativo.');
        }

        $owner = $this->withdrawalTenantOwner($withdrawal);
        if ($owner === null) {
            MerchantWithdrawalService::releasePayoutApproval($withdrawal);

            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Titular da conta (infoprodutor) não encontrado.');
        }

        $result = $this->sendCajuPayWithdrawalPayout($withdrawal->fresh(), $owner);

        if (! ($result['ok'] ?? false)) {
            $this->recordCajuPayWithdrawalFailure($withdrawal, $result);
            MerchantWithdrawalService::releasePayoutApproval($withdrawal->fresh());

            PlatformAuditService::log('platform.withdrawal.cajupay_retry_failed', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $result['error'] ?? null,
                'cajupay_error_code' => $result['cajupay_error_code'] ?? null,
            ], $request);

            return redirect()->route('plataforma.saques.index')
                ->with('error', $result['error'] ?? 'Falha ao reprocessar o saque.');
        }

        $withdrawal->update([
            'payout_manual' => false,
            'payout_provider' => 'cajupay',
            'payout_external_id' => $result['external_id'] ?? null,
            'payout_meta' => array_filter([
                'api_status' => $result['status'] ?? null,
                'requested_at' => now()->toIso8601String(),
            ]),
        ]);

        PlatformAuditService::log('platform.withdrawal.cajupay_retry_succeeded', ['withdrawal_id' => $withdrawal->id], $request);

        return redirect()->route('plataforma.saques.index')
            ->with('success', 'Saque reprocessado via CajuPay. Aguardando confirmação para marcar como pago.');
    }

    public function rejectWithdrawal(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($withdrawal->status !== 'pending') {
            return redirect()->route('plataforma.saques.index')
                ->with('error', 'Este saque não está pendente.');
        }

        MerchantWithdrawalService::reject($withdrawal, $validated['admin_note'] ?? null);

        PlatformAuditService::log('platform.withdrawal.rejected', ['withdrawal_id' => $withdrawal->id], $request);

        return redirect()->route('plataforma.saques.index')
            ->with('success', 'Saque rejeitado e saldo devolvido ao infoprodutor.');
    }

    private function withdrawalTenantOwner(Withdrawal $withdrawal): ?User
    {
        $tenantId = (int) $withdrawal->tenant_id;
        $owner = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($owner === null) {
            $owner = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
        }

        return $owner;
    }

    /**
     * @return array{ok: true, external_id?: string|null, status?: int|null}|array{ok: false, error: string, cajupay_error_code?: string|null}
     */
    private function sendCajuPayWithdrawalPayout(Withdrawal $withdrawal, User $owner): array
    {
        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $pixKey = PayoutUserSettings::cajuPixKey($settings);
        $pixKeyType = PayoutUserSettings::cajuPixKeyType($settings);
        $keyOwnerDocument = PayoutUserSettings::cajuPixOwnerDocument($settings);

        if ($pixKey === '' || $pixKeyType === '') {
            return ['ok' => false, 'error' => 'O infoprodutor precisa cadastrar uma chave PIX para saque em Financeiro (painel do vendedor).'];
        }
        if ($keyOwnerDocument === '') {
            return ['ok' => false, 'error' => 'O infoprodutor precisa atualizar o CPF/CNPJ do titular no cadastro da chave PIX em Financeiro.'];
        }

        $payout = new CajuPayPayoutService;
        $apiResult = $payout->sendWithdrawalToPixKey(
            $withdrawal,
            null,
            $pixKey,
            $pixKeyType,
            $keyOwnerDocument
        );

        if ($apiResult['ok'] ?? false) {
            return [
                'ok' => true,
                'external_id' => $apiResult['external_id'] ?? null,
                'status' => $apiResult['status'] ?? null,
            ];
        }

        return [
            'ok' => false,
            'error' => $apiResult['error'] ?? 'Falha ao enviar o saque.',
            'cajupay_error_code' => $apiResult['cajupay_error_code'] ?? null,
        ];
    }

    /**
     * @param  array{ok: false, error: string, cajupay_error_code?: string|null}  $result
     */
    private function recordCajuPayWithdrawalFailure(Withdrawal $withdrawal, array $result): void
    {
        $prev = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta = array_merge($prev, [
            'last_error' => $result['error'] ?? 'Erro desconhecido',
            'last_attempt_at' => now()->toIso8601String(),
        ]);
        if (($result['cajupay_error_code'] ?? null) === 'insufficient_funds') {
            $meta['cajupay_error_code'] = 'insufficient_funds';
        } else {
            unset($meta['cajupay_error_code']);
        }

        $withdrawal->update([
            'payout_provider' => 'cajupay',
            'payout_meta' => $meta,
        ]);
    }
}
