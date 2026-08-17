<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\HandlesLoginTotpChallenge;
use App\Http\Controllers\Controller;
use App\Models\TeamAuditLog;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAuditService;
use App\Services\SellerActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorLoginController extends Controller
{
    use HandlesLoginTotpChallenge;

    public function showSeller(Request $request): Response|RedirectResponse
    {
        if ($this->hasValidPendingLoginTotp($request, 'seller') === null) {
            return redirect()->route('login')->with('error', 'Sessão de verificação expirada. Faça login novamente.');
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'submitUrl' => route('login.two-factor.verify'),
            'cancelUrl' => route('login.two-factor.cancel'),
            'title' => 'Verificação em dois fatores',
        ]);
    }

    public function verifySeller(Request $request): RedirectResponse
    {
        $user = $this->hasValidPendingLoginTotp($request, 'seller');
        if ($user === null) {
            return redirect()->route('login')->with('error', 'Sessão de verificação expirada. Faça login novamente.');
        }

        $validated = $request->validate([
            'totp_code' => ['required', 'string', 'max:16'],
        ]);

        if (! PlatformTotpService::verifyCodeForUser($user, $validated['totp_code'])) {
            return back()->withErrors([
                'totp_code' => 'Código 2FA inválido ou expirado.',
            ]);
        }

        if ($user->tenant_id && $user->canAccessSellerPanel()) {
            TeamAuditLog::create([
                'tenant_id' => $user->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'auth.login',
                'metadata' => [
                    'method' => 'POST',
                    'path' => '/login/2fa',
                    'totp' => true,
                ],
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
            SellerActivityLogService::record(
                actor: $user,
                action: SellerActivityLogService::AUTH_LOGIN,
                metadata: ['totp' => true],
                request: $request,
            );
        }

        return $this->completeLoginAfterTotp($request, $user);
    }

    public function cancelSeller(Request $request): RedirectResponse
    {
        $this->clearPendingLoginTotp($request);

        return redirect()->route('login');
    }

    public function showPlatform(Request $request): Response|RedirectResponse
    {
        if ($this->hasValidPendingLoginTotp($request, 'platform') === null) {
            return redirect()->route('plataforma.login')->with('error', 'Sessão de verificação expirada. Faça login novamente.');
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'submitUrl' => route('plataforma.login.two-factor.verify'),
            'cancelUrl' => route('plataforma.login.two-factor.cancel'),
            'title' => 'Verificação em dois fatores',
        ]);
    }

    public function verifyPlatform(Request $request): RedirectResponse
    {
        $user = $this->hasValidPendingLoginTotp($request, 'platform');
        if ($user === null) {
            return redirect()->route('plataforma.login')->with('error', 'Sessão de verificação expirada. Faça login novamente.');
        }

        $validated = $request->validate([
            'totp_code' => ['required', 'string', 'max:16'],
        ]);

        if (! PlatformTotpService::verifyCodeForUser($user, $validated['totp_code'])) {
            return back()->withErrors([
                'totp_code' => 'Código 2FA inválido ou expirado.',
            ]);
        }

        PlatformAuditService::log('platform.auth.login', [
            'email' => $user->email,
            'totp' => true,
        ], $request);

        return $this->completeLoginAfterTotp($request, $user);
    }

    public function cancelPlatform(Request $request): RedirectResponse
    {
        $this->clearPendingLoginTotp($request);

        return redirect()->route('plataforma.login');
    }
}
