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
     * Para Mercado Pago, prioriza gateway_credential_id gravado no pedido na cobrança.
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

        if ($gatewaySlug === 'mercadopago' && $order !== null) {
            $pinned = self::resolvePinnedMercadoPagoCredential($order);
            if ($pinned !== null) {
                return $pinned;
            }
        }

        $credential = GatewayCredential::resolveForPayment($tenantId, $gatewaySlug);
        if ($credential === null) {
            return null;
        }

        $credentials = $credential->getDecryptedCredentials();

        return $credentials === [] ? null : $credentials;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolvePinnedMercadoPagoCredential(Order $order): ?array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $credentialId = (int) ($meta['gateway_credential_id'] ?? 0);
        if ($credentialId <= 0) {
            return null;
        }

        $credential = GatewayCredential::query()
            ->where('id', $credentialId)
            ->where('gateway_slug', 'mercadopago')
            ->where('is_connected', true)
            ->first();

        if ($credential === null) {
            return null;
        }

        if (! $credential->isEnabledForPayments()) {
            return null;
        }

        $credentials = $credential->getDecryptedCredentials();

        return $credentials === [] ? null : $credentials;
    }
}
