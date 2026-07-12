<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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

    protected function completeLoginAfterTotp(Request $request, User $user): RedirectResponse|Response
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
            return $this->inertiaOrRedirectAfterLogin($request, $intended);
        }

        if ($panel === 'platform' && $user->canAccessPlatformPanel()) {
            return $this->inertiaOrRedirectAfterLogin($request, route('plataforma.dashboard'));
        }

        if ($user->canAccessSellerPanel()) {
            return $this->inertiaOrRedirectAfterLogin($request, '/dashboard');
        }

        if ($user->canAccessCustomerPanel()) {
            $request->session()->put('panel_context', 'customer');

            return $this->inertiaOrRedirectAfterLogin($request, '/painel-cliente');
        }

        return $this->inertiaOrRedirectAfterLogin($request, '/painel-cliente');
    }

    /**
     * Após login, visitas Inertia não devem seguir 302 via XHR (quebra com url.intended).
     * Força navegação completa via X-Inertia-Location (sempre path relativo, no host atual).
     */
    protected function inertiaOrRedirectAfterLogin(Request $request, string $defaultUrl): RedirectResponse|Response
    {
        if ($request->header('X-Inertia')) {
            // url.intended costuma apontar para páginas protegidas visitadas antes do login;
            // com Inertia isso vira loop (POST login → GET intended → GET login). Vai ao destino padrão.
            $request->session()->forget('url.intended');

            return Inertia::location($this->toInertiaLocationPath($defaultUrl));
        }

        $intended = $request->session()->pull('url.intended');
        $url = is_string($intended) && $intended !== '' && $this->isSafeLocalRedirect($intended)
            ? $intended
            : $defaultUrl;

        return redirect()->to($url);
    }

    private function isSafeLocalRedirect(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && str_starts_with($url, $appUrl.'/')) {
            return true;
        }

        return false;
    }

    private function toInertiaLocationPath(string $url): string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && str_starts_with($url, $appUrl)) {
            $relative = substr($url, strlen($appUrl));

            return ($relative === '' || $relative === false) ? '/' : $relative;
        }

        $parts = parse_url($url);
        if (! isset($parts['path'])) {
            return '/';
        }

        $path = $parts['path'];
        if (! empty($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        return $path;
    }
}
