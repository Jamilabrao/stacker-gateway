<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Services\MercadoPago\MercadoPagoBalanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MercadoPagoBalanceController extends Controller
{
    public function __invoke(Request $request, MercadoPagoBalanceService $balanceService): Response
    {
        $credential = GatewayCredential::resolveForTenantOrGlobal(null, 'mercadopago');
        $accessToken = trim((string) ($credential?->getDecryptedCredentials()['access_token'] ?? ''));

        if ($accessToken === '') {
            return Inertia::render('Platform/Ops/MercadoPagoBalance', [
                'status' => 'missing_credentials',
                'balance' => null,
                'error' => null,
                'gateway_connected' => (bool) ($credential?->is_connected ?? false),
            ]);
        }

        try {
            $balance = $balanceService->fetchBalance($accessToken);

            return Inertia::render('Platform/Ops/MercadoPagoBalance', [
                'status' => 'ok',
                'balance' => $balance,
                'error' => null,
                'gateway_connected' => (bool) ($credential?->is_connected ?? false),
            ]);
        } catch (Throwable $e) {
            return Inertia::render('Platform/Ops/MercadoPagoBalance', [
                'status' => 'error',
                'balance' => null,
                'error' => $e->getMessage(),
                'gateway_connected' => (bool) ($credential?->is_connected ?? false),
            ]);
        }
    }
}
