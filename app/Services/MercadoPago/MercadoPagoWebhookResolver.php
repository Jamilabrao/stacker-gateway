<?php

namespace App\Services\MercadoPago;

use App\Gateways\GatewayRegistry;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookResolver
{
    /**
     * Consulta o pagamento na API MP (doc oficial) para obter external_reference quando o webhook não traz.
     *
     * @return array{external_reference: ?string, status: ?string}|null
     */
    public function fetchPaymentFromApi(string $paymentId, ?int $tenantId = null): ?array
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            return null;
        }

        $driver = GatewayRegistry::driver('mercadopago');
        if (! $driver instanceof MercadoPagoDriver) {
            return null;
        }

        foreach ($this->credentialCandidates($tenantId) as $credentials) {
            $details = $driver->getPaymentDetails($paymentId, $credentials);
            if ($details !== null) {
                return $details;
            }
        }

        return null;
    }

    public function findOrderForWebhook(string $paymentId, ?string $externalReference, ?int $tenantId = null): ?Order
    {
        $completion = app(MercadoPagoCheckoutCompletionService::class);
        $order = $completion->findOrderForWebhook($paymentId, $externalReference);
        if ($order !== null) {
            return $order;
        }

        $externalReference = trim((string) $externalReference);
        if ($externalReference === '') {
            $details = $this->fetchPaymentFromApi($paymentId, $tenantId);
            $externalReference = trim((string) ($details['external_reference'] ?? ''));
            if ($externalReference !== '') {
                $order = $completion->findOrderForWebhook($paymentId, $externalReference);
                if ($order !== null) {
                    return $order;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function credentialCandidates(?int $tenantId): array
    {
        $seen = [];
        $out = [];

        $global = GatewayCredential::resolveForPayment(null, 'mercadopago');
        if ($global !== null) {
            $creds = $global->getDecryptedCredentials();
            if ($creds !== []) {
                $out[] = $creds;
                $seen[md5(json_encode($creds))] = true;
            }
        }

        if ($tenantId !== null) {
            $tenant = GatewayCredential::resolveForPayment($tenantId, 'mercadopago');
            if ($tenant !== null) {
                $creds = $tenant->getDecryptedCredentials();
                $hash = md5(json_encode($creds));
                if ($creds !== [] && ! isset($seen[$hash])) {
                    $out[] = $creds;
                }
            }
        }

        if ($out === []) {
            Log::debug('MercadoPagoWebhookResolver: nenhuma credencial disponível', [
                'tenant_id' => $tenantId,
            ]);
        }

        return $out;
    }
}
