<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMercadoPagoBalanceToolEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('getfy.mp_balance_tool.enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
