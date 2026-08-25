<?php

namespace App\Http\Middleware;

use App\Services\SellerIntegrationVisibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerIntegrationEnabled
{
    public function handle(Request $request, Closure $next, string $integrationId): Response
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id !== null ? (int) $user->tenant_id : null;

        if (! SellerIntegrationVisibility::isKnown($integrationId)
            || ! SellerIntegrationVisibility::effectiveForTenant($integrationId, $tenantId)) {
            abort(Response::HTTP_FORBIDDEN, 'Esta integração não está disponível.');
        }

        return $next($request);
    }
}
