<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestPlatform
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        if ($user->canAccessPlatformPanel()) {
            return redirect()->route('plataforma.dashboard');
        }

        // Utilizador autenticado sem acesso ao painel da plataforma: não revelar rotas
        // internas nem o papel da conta (anti-enumeração / anti-reconhecimento de operação).
        if ($user->canAccessSellerPanel()) {
            return redirect('/dashboard')->with(
                'error',
                'Acesso não permitido com esta sessão. Saia da conta e tente novamente se tiver outro acesso.'
            );
        }

        return redirect('/area-membros')->with(
            'error',
            'Acesso não permitido com esta sessão.'
        );
    }
}
