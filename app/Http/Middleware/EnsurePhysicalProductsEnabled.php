<?php

namespace App\Http\Middleware;

use App\Services\PhysicalProductAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePhysicalProductsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PhysicalProductAccess::globalEnabled()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
