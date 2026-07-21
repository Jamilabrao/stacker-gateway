<?php

namespace App\Http\Controllers\Concerns;

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
            $code = preg_replace('/\D/', '', (string) $request->input('totp_code', '')) ?? '';
            if ($code === '') {
                $this->throwStepUpError('Informe o código 2FA para continuar.', $redirectRoute);
            }
            if (! PlatformTotpService::verifyCodeForUser($user, $code)) {
                $this->throwStepUpError('Código 2FA inválido ou expirado.', $redirectRoute);
            }
        }

        if ($requireManualPin && WithdrawalPolicyService::requiresManualApprovalPin()) {
            if (! WithdrawalPolicyService::hasManualApprovalPin()) {
                $this->throwStepUpError('Configure o PIN de aprovação manual em Financeiro > Saques.', $redirectRoute);
            }
            $pin = (string) $request->input('manual_approval_pin', '');
            if (! WithdrawalPolicyService::verifyManualApprovalPin($pin)) {
                $this->throwStepUpError('PIN de confirmação inválido.', $redirectRoute);
            }
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
