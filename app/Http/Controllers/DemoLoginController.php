<?php

namespace App\Http\Controllers;

use App\Support\DemoMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoLoginController extends Controller
{
    public function loginAdmin(Request $request): RedirectResponse
    {
        return $this->loginAs($request, 'admin', route('plataforma.dashboard'));
    }

    public function loginSeller(Request $request): RedirectResponse
    {
        $request->session()->put('panel_context', 'seller');

        return $this->loginAs($request, 'seller', '/dashboard');
    }

    private function loginAs(Request $request, string $role, string $redirectTo): RedirectResponse
    {
        if (! DemoMode::isEnabled()) {
            abort(404);
        }

        $user = $role === 'admin' ? DemoMode::adminUser() : DemoMode::sellerUser();
        if (! $user) {
            return redirect()->back()->with('error', 'Conta demo não configurada. Configure em Plataforma → Configurações → Demo (com GETFY_DEMO_MODE=false).');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended($redirectTo);
    }
}
