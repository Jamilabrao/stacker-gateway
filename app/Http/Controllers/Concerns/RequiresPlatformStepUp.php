<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait RequiresPlatformStepUp
{
    protected function validatePlatformStepUp(
        Request $request,
        bool $requireManualPin = false,
        ?string $redirectRoute = null
    ): void {
        $user = $request->user();
        if ($user === null || ! $user->canAccessPlatformPanel()) {
            abort(403);
        }

        if (PlatformTotpService::isRequiredFor($user)) {
            $this->assertValidTotp($request, $user, $redirectRoute);

            return;
        }

        if ($requireManualPin) {
            $this->assertValidOperationPin($request, $redirectRoute);
        }
    }

    /**
     * Aprovação de saque (CajuPay ou manual): 2FA dispensa PIN; sem 2FA o PIN é obrigatório.
     * Sem nenhum dos dois, a ação é recusada.
     */
    protected function validateWithdrawalPayoutStepUp(Request $request, ?string $redirectRoute = null): void
    {
        $user = $request->user();
        if ($user === null || ! $user->canAccessPlatformPanel()) {
            abort(403);
        }

        if (! WithdrawalPolicyService::hasPayoutSecurityBarrier($user)) {
            $this->throwStepUpError(
                'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques para autorizar pagamentos.',
                $redirectRoute
            );
        }

        if (PlatformTotpService::isEnabledFor($user)) {
            $this->assertValidTotp($request, $user, $redirectRoute);

            return;
        }

        $this->assertValidOperationPin($request, $redirectRoute);
    }

    protected function assertValidTotp(Request $request, User $user, ?string $redirectRoute = null): void
    {
        $code = preg_replace('/\D/', '', (string) $request->input('totp_code', '')) ?? '';
        if ($code === '') {
            $this->throwStepUpError('Informe o código 2FA para continuar.', $redirectRoute);
        }
        if (! PlatformTotpService::verifyCodeForUser($user, $code)) {
            $this->throwStepUpError('Código 2FA inválido ou expirado.', $redirectRoute);
        }
    }

    protected function assertValidOperationPin(Request $request, ?string $redirectRoute = null): void
    {
        if (! WithdrawalPolicyService::hasManualApprovalPin()) {
            $this->throwStepUpError('Configure o PIN de operação em Financeiro > Saques.', $redirectRoute);
        }
        $pin = (string) $request->input('manual_approval_pin', '');
        if (! WithdrawalPolicyService::verifyManualApprovalPin($pin)) {
            $this->throwStepUpError('PIN de confirmação inválido.', $redirectRoute);
        }
    }

    protected function throwStepUpError(string $message, ?string $redirectRoute = null): never
    {
        if (request()->expectsJson()) {
            throw ValidationException::withMessages(['totp_code' => $message]);
        }

        if ($redirectRoute !== null) {
            throw new HttpResponseException(
                redirect()->route($redirectRoute)->with('error', $message)
            );
        }

        throw ValidationException::withMessages(['totp_code' => $message]);
    }
}
