<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(403, 'Acesso não autorizado.');
        }
        if ($user->canAccessPlatformPanel()) {
            return redirect()
                ->route('plataforma.dashboard')
                ->with('error', 'Use o painel da plataforma.');
        }
        if (! $user->canAccessSellerPanel()) {
            if ($request->expectsJson()) {
                abort(403, 'Acesso não autorizado.');
            }

            return redirect($user->sellerPanelFallbackUrl())
                ->with('info', 'O painel de vendas é para infoprodutores. Você foi redirecionado para o painel do cliente.');
        }

        if ($user->sellerAccountAccessBlocked()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Conta suspensa ou bloqueada. Contate o suporte.']);
        }

        if ($user->mustSubmitKycBeforeSellerPanel() && ! $this->isKycOnboardingRoute($request)) {
            return redirect('/financeiro?tab=seus-dados')
                ->with('info', 'Envie seus documentos de verificação de identidade (KYC) para acessar o painel do infoprodutor.');
        }

        return $next($request);
    }

    private function isKycOnboardingRoute(Request $request): bool
    {
        return $request->routeIs(
            'kyc.upload',
            'kyc.document',
            'kyc.finalize',
            'kyc.store',
            'financeiro.seller.index',
            'logout',
            'panel.switch',
            'profile.index',
            'profile.update',
            'profile.update-username',
            'profile.update-password',
        );
    }
}
