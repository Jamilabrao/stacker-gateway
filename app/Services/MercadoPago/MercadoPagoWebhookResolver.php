<?php

namespace App\Services\MercadoPago;

use App\Gateways\GatewayRegistry;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Models\Order;
use App\Support\MercadoPagoCredentialCandidates;
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

        $stub = new Order(['tenant_id' => $tenantId]);
        $stub->exists = false;

        foreach (MercadoPagoCredentialCandidates::forOrder($stub) as $candidate) {
            $details = $driver->getPaymentDetails($paymentId, $candidate['credentials']);
            if ($details !== null) {
                return $details;
            }
        }

        Log::debug('MercadoPagoWebhookResolver: payment não encontrado em nenhuma credencial', [
            'payment_id' => $paymentId,
            'tenant_id' => $tenantId,
        ]);

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
}
