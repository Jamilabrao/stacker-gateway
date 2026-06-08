<?php

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockMutationsWhenDemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DemoMode::isAllowedMutation($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Modo demonstração ativo. Alterações desabilitadas.',
            ], 403);
        }

        return redirect()->back()->with('error', 'Modo demonstração ativo. Alterações desabilitadas.');
    }
}
