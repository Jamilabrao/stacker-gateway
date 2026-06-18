<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TenantWallet;
use App\Services\Api\ApiAuthContext;
use App\Services\ApiPixAccess;
use App\Services\MerchantOperationalGuard;
use App\Services\MerchantWalletAdminBlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class BalanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::WITHDRAWALS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        $tenantId = (int) $ctx->application->tenant_id;
        MerchantOperationalGuard::assertCanRequestWithdrawal(
            \App\Services\Api\ApiWithdrawalService::resolveInfoprodutor($tenantId)
        );

        $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->first();
        if ($wallet === null) {
            return response()->json([
                'available_pix' => 0.0,
                'available_card' => 0.0,
                'available_boleto' => 0.0,
                'available_balance' => 0.0,
                'pending_balance' => 0.0,
            ]);
        }

        $response = [
            'available_pix' => MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, 'pix'),
            'available_card' => MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, 'card'),
            'available_boleto' => MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, 'boleto'),
            'available_balance' => (float) ($wallet->available_balance ?? 0),
            'pending_balance' => (float) ($wallet->pending_balance ?? 0),
        ];

        if (Schema::hasColumn('tenant_wallets', 'pending_pix')) {
            $response['pending_pix'] = (float) ($wallet->pending_pix ?? 0);
            $response['pending_card'] = (float) ($wallet->pending_card ?? 0);
            $response['pending_boleto'] = (float) ($wallet->pending_boleto ?? 0);
        }

        return response()->json($response);
    }
}
