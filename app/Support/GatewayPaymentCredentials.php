<?php

namespace App\Support;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Services\CajuPay\CajuPayAccountResolver;

class GatewayPaymentCredentials
{
    /**
     * Credenciais usadas para consultar status, estornar ou reconciliar um pagamento.
     * Para CajuPay, prioriza a conta vinculada ao pedido (orders.cajupay_account_id).
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(?int $tenantId, string $gatewaySlug, ?Order $order = null): ?array
    {
        if ($gatewaySlug === 'cajupay') {
            $resolver = app(CajuPayAccountResolver::class);
            if ($order !== null) {
                return $resolver->credentialsForOrder($order);
            }

            $credentials = $resolver->credentialsForTenant($tenantId);

            return $credentials === [] ? null : $credentials;
        }

        $credential = GatewayCredential::resolveForPayment($tenantId, $gatewaySlug);
        if ($credential === null) {
            return null;
        }

        $credentials = $credential->getDecryptedCredentials();

        return $credentials === [] ? null : $credentials;
    }
}
