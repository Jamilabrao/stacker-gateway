<?php

namespace App\Support;

use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Models\GatewayCredential;
use App\Models\Order;

final class MercadoPagoCredentialCandidates
{
    /**
     * Credenciais MP para consultar/reconciliar um pagamento (ordem: pin do pedido → global → tenant).
     *
     * @return list<array{credentials: array<string, mixed>, credential_id: ?int, label: string}>
     */
    public static function forOrder(?Order $order): array
    {
        $tenantId = $order?->tenant_id;
        $seen = [];
        $out = [];

        $push = function (array $credentials, ?int $credentialId, string $label) use (&$seen, &$out): void {
            if ($credentials === []) {
                return;
            }
            $hash = md5(json_encode($credentials));
            if (isset($seen[$hash])) {
                return;
            }
            $seen[$hash] = true;
            $out[] = [
                'credentials' => $credentials,
                'credential_id' => $credentialId,
                'label' => $label,
            ];
        };

        if ($order !== null) {
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $pinnedId = (int) ($meta['gateway_credential_id'] ?? 0);
            if ($pinnedId > 0) {
                $pinned = GatewayCredential::query()
                    ->where('id', $pinnedId)
                    ->where('gateway_slug', 'mercadopago')
                    ->where('is_connected', true)
                    ->first();
                if ($pinned !== null && $pinned->isEnabledForPayments()) {
                    $push($pinned->getDecryptedCredentials(), $pinned->id, "pinned:{$pinned->id}");
                }
            }
        }

        $global = GatewayCredential::query()
            ->whereNull('tenant_id')
            ->where('gateway_slug', 'mercadopago')
            ->where('is_connected', true)
            ->first();
        if ($global !== null && $global->isEnabledForPayments()) {
            $push($global->getDecryptedCredentials(), $global->id, 'global');
        }

        if ($tenantId !== null) {
            $tenant = GatewayCredential::query()
                ->where('tenant_id', $tenantId)
                ->where('gateway_slug', 'mercadopago')
                ->where('is_connected', true)
                ->first();
            if ($tenant !== null && $tenant->isEnabledForPayments()) {
                $push($tenant->getDecryptedCredentials(), $tenant->id, "tenant:{$tenantId}");
            }
        }

        return $out;
    }

    /**
     * @return array{payment_id: string, credentials: array<string, mixed>, label: string}|null
     */
    public static function findApprovedPaymentForOrder(Order $order, MercadoPagoDriver $driver): ?array
    {
        $paymentId = trim((string) ($order->gateway_id ?? ''));

        foreach (self::forOrder($order) as $candidate) {
            $credentials = $candidate['credentials'];
            $label = $candidate['label'];

            if ($paymentId !== '') {
                $status = $driver->getTransactionStatus($paymentId, $credentials);
                if ($status === 'paid') {
                    return [
                        'payment_id' => $paymentId,
                        'credentials' => $credentials,
                        'label' => $label,
                    ];
                }
            }

            $foundId = $driver->findApprovedPaymentByExternalReference((string) $order->id, $credentials);
            if ($foundId !== null) {
                return [
                    'payment_id' => $foundId,
                    'credentials' => $credentials,
                    'label' => $label,
                ];
            }
        }

        return null;
    }
}
