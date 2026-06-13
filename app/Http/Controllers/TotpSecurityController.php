<?php

namespace App\Http\Controllers;

use App\Models\TeamAuditLog;
use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TotpSecurityController extends Controller
{
    public function beginTotp(Request $request): JsonResponse
    {
        $user = $this->authorizedUser($request);

        $data = PlatformTotpService::beginEnrollment($user->fresh());

        return response()->json([
            'secret' => $data['secret'],
            'otpauth_url' => $data['otpauth_url'],
        ]);
    }

    public function confirmTotp(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser($request);

        $validated = $request->validate([
            'totp_code' => ['required', 'string', 'max:16'],
        ]);

        if (! PlatformTotpService::confirmEnrollment($user->fresh(), $validated['totp_code'])) {
            return redirect()->to($this->profileUrl($user))
                ->with('error', 'Código 2FA inválido. Tente novamente.');
        }

        $this->logTotpEvent($request, $user, 'enabled');

        return redirect()->to($this->profileUrl($user))
            ->with('success', 'Autenticação em dois fatores ativada.');
    }

    public function disableTotp(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser($request);

        $validated = $request->validate([
            'totp_code' => ['required', 'string', 'max:16'],
        ]);

        if (! PlatformTotpService::disable($user->fresh(), $validated['totp_code'])) {
            return redirect()->to($this->profileUrl($user))
                ->with('error', 'Código 2FA inválido.');
        }

        $this->logTotpEvent($request, $user, 'disabled');

        return redirect()->to($this->profileUrl($user))
            ->with('success', 'Autenticação em dois fatores desativada.');
    }

    private function authorizedUser(Request $request): User
    {
        $user = $request->user();
        if ($user === null || (! $user->canAccessPlatformPanel() && ! $user->canAccessSellerPanel())) {
            abort(403);
        }

        return $user;
    }

    private function profileUrl(User $user): string
    {
        return $user->canAccessPlatformPanel()
            ? route('plataforma.profile.index')
            : route('profile.index');
    }

    private function logTotpEvent(Request $request, User $user, string $action): void
    {
        if ($user->canAccessPlatformPanel()) {
            PlatformAuditService::log('platform.security.totp_'.$action, [], $request);

            return;
        }

        if ($user->tenant_id && $user->canAccessSellerPanel()) {
            TeamAuditLog::create([
                'tenant_id' => $user->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'auth.security.totp_'.$action,
                'metadata' => [
                    'method' => $request->method(),
                    'path' => $request->path(),
                ],
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        }
    }
}
