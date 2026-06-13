<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait HandlesLoginTotpChallenge
{
    protected function redirectToLoginTotpChallenge(
        Request $request,
        User $user,
        bool $remember,
        string $panel,
        string $challengeRoute,
        ?string $intended = null,
    ): RedirectResponse {
        Auth::logout();

        $request->session()->put('login.totp.user_id', $user->id);
        $request->session()->put('login.totp.remember', $remember);
        $request->session()->put('login.totp.panel', $panel);
        $request->session()->put('login.totp.intended', $intended);
        $request->session()->put('login.totp.expires_at', now()->addMinutes(10)->timestamp);

        return redirect()->route($challengeRoute);
    }

    protected function hasValidPendingLoginTotp(Request $request, string $expectedPanel): ?User
    {
        $userId = $request->session()->get('login.totp.user_id');
        $panel = $request->session()->get('login.totp.panel');
        $expiresAt = (int) $request->session()->get('login.totp.expires_at', 0);

        if (! is_numeric($userId) || $panel !== $expectedPanel || $expiresAt < now()->timestamp) {
            $this->clearPendingLoginTotp($request);

            return null;
        }

        $user = User::query()->find((int) $userId);
        if ($user === null || ! PlatformTotpService::requiresLoginChallenge($user)) {
            $this->clearPendingLoginTotp($request);

            return null;
        }

        return $user;
    }

    protected function clearPendingLoginTotp(Request $request): void
    {
        $request->session()->forget([
            'login.totp.user_id',
            'login.totp.remember',
            'login.totp.panel',
            'login.totp.intended',
            'login.totp.expires_at',
        ]);
    }

    protected function completeLoginAfterTotp(Request $request, User $user): RedirectResponse
    {
        $remember = (bool) $request->session()->get('login.totp.remember', false);
        $intended = $request->session()->get('login.totp.intended');
        $panel = (string) $request->session()->get('login.totp.panel', '');

        $this->clearPendingLoginTotp($request);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        if ($panel === 'seller' && $user->canAccessSellerPanel()) {
            $request->session()->put('panel_context', 'seller');
        }

        if (is_string($intended) && $intended !== '') {
            return redirect()->intended($intended);
        }

        if ($panel === 'platform' && $user->canAccessPlatformPanel()) {
            return redirect()->intended(route('plataforma.dashboard'));
        }

        if ($user->canAccessSellerPanel()) {
            return redirect()->intended('/dashboard');
        }

        if ($user->canAccessCustomerPanel()) {
            $request->session()->put('panel_context', 'customer');

            return redirect()->intended('/painel-cliente');
        }

        return redirect()->intended('/painel-cliente');
    }
}
