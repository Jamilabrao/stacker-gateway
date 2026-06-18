<?php

namespace App\Http\Middleware;

use App\Services\Api\ApiAuthContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $ctx = ApiAuthContext::fromRequest($request);

        foreach ($scopes as $scope) {
            if ($ctx->hasScope($scope)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Insufficient API key permissions.'], 403);
    }
}
