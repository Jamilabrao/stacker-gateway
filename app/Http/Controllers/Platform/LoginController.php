<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\ClearsAuthSessionCookies;
use App\Http\Controllers\Concerns\HandlesLoginTotpChallenge;
use App\Http\Controllers\Concerns\ValidatesAuthTurnstile;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAuditService;
use App\Support\LoginTurnstileSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LoginController extends Controller
{
    use ClearsAuthSessionCookies;
    use HandlesLoginTotpChallenge;
    use ValidatesAuthTurnstile;

    public function showLoginForm(Request $request): Response
    {
        $request->session()->forget('url.intended');

        return Inertia::render('Platform/Auth/Login', [
            'login_turnstile' => LoginTurnstileSettings::publicConfig(),
        ]);
    }

    public function login(Request $request): RedirectResponse|HttpResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($turnstileError = $this->validateLoginTurnstile($request)) {
            return $turnstileError;
        }

        if (Auth::attempt($request->only('email', 'password'), (bool) $request->boolean('remember'))) {
            $user = Auth::user();
            if (! $user instanceof User || ! $user->canAccessPlatformPanel()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'email' => 'Credenciais inválidas.',
                ])->onlyInput('email');
            }

            if (PlatformTotpService::requiresLoginChallenge($user)) {
                return $this->redirectToLoginTotpChallenge(
                    $request,
                    $user,
                    (bool) $request->boolean('remember'),
                    'platform',
                    'plataforma.login.two-factor',
                    route('plataforma.dashboard'),
                );
            }

            $request->session()->regenerate();

            PlatformAuditService::log('platform.auth.login', [
                'email' => $user->email,
            ], $request);

            return $this->inertiaOrRedirectAfterLogin($request, route('plataforma.dashboard'));
        }

        return back()->withErrors([
            'email' => 'Credenciais inválidas.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($request->user()?->canAccessPlatformPanel()) {
            PlatformAuditService::log('platform.auth.logout', [], $request);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->clearAuthSessionCookies();

        return redirect()->to('/plataforma/login');
    }
}
