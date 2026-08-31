<?php

namespace App\Http\Controllers;

use App\Gateways\Cielo\CieloHttpClient;
use App\Models\GatewayCredential;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gera o AccessToken do Silent Order Post no servidor (ClientSecret nunca vai ao browser).
 */
class CieloSopController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required'],
        ]);

        $product = Product::query()->find($validated['product_id']);
        if ($product === null) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $credential = GatewayCredential::resolveForPayment($product->tenant_id, 'cielo');
        if ($credential === null || ! $credential->isEnabledForPayments()) {
            return response()->json(['message' => 'Cielo não está configurada para cartão.'], 422);
        }

        $credentials = $credential->getDecryptedCredentials();
        try {
            $sop = CieloHttpClient::createSopAccessToken($credentials);
        } catch (\Throwable $e) {
            Log::warning('CieloSopController: falha ao gerar AccessToken SOP', [
                'product_id' => $product->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'access_token' => $sop['AccessToken'],
            'environment' => $sop['environment'],
        ]);
    }
}
