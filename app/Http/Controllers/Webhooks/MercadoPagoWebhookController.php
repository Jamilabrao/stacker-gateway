<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\MercadoPago\MercadoPagoCheckoutCompletionService;
use App\Services\MercadoPago\MercadoPagoWebhookResolver;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    /**
     * Handle Mercado Pago webhook (POST/GET /webhooks/gateways/mercadopago).
     * Suporta JSON (webhooks v1) e IPN legado (?topic=payment&id=).
     * Responde 200 rápido e enfileira processamento — sem chamada síncrona à API.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $parsed = $this->parseNotification($request, $payload);

        if ($parsed['payment_id'] === null) {
            return response()->json(['received' => true]);
        }

        $paymentId = $parsed['payment_id'];
        $externalReference = $parsed['external_reference'];
        $event = $parsed['event'];

        $completion = app(MercadoPagoCheckoutCompletionService::class);
        $resolver = app(MercadoPagoWebhookResolver::class);
        $order = $resolver->findOrderForWebhook($paymentId, $externalReference);

        if ($externalReference === null && $order !== null) {
            $externalReference = (string) $order->id;
        }

        if ($externalReference === null) {
            $details = $resolver->fetchPaymentFromApi($paymentId, $order?->tenant_id);
            $fetchedRef = trim((string) ($details['external_reference'] ?? ''));
            if ($fetchedRef !== '') {
                $externalReference = $fetchedRef;
                if ($order === null) {
                    $order = $completion->findOrderForWebhook($paymentId, $externalReference);
                }
            }
        }

        $enrichedPayload = array_merge($payload, [
            'webhook_source' => 'mercadopago_webhook',
            'external_reference' => $externalReference ?? ($order !== null ? (string) $order->id : null),
        ]);

        if ($order === null) {
            if ($this->looksLikePaymentNotification($parsed)) {
                $completion->storePendingPaidWebhook($paymentId, $externalReference, $enrichedPayload);
                Log::info('MercadoPagoWebhook: paid guardado até pedido existir', [
                    'payment_id' => $paymentId,
                    'external_reference' => $externalReference,
                ]);
            } else {
                Log::debug('MercadoPagoWebhook: order not found', [
                    'payment_id' => $paymentId,
                    'external_reference' => $externalReference,
                    'event' => $event,
                ]);
            }

            PaymentWebhookDispatcher::dispatch('mercadopago', $paymentId, $event, 'pending', $enrichedPayload);

            return response()->json(['received' => true]);
        }

        PaymentWebhookDispatcher::dispatch(
            'mercadopago',
            $paymentId,
            $event,
            'pending',
            $enrichedPayload
        );

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payment_id: ?string, external_reference: ?string, event: string}
     */
    private function parseNotification(Request $request, array $payload): array
    {
        $topic = strtolower(trim((string) ($request->query('topic') ?? $request->input('topic') ?? '')));
        $queryId = $request->query('id') ?? $request->input('id');

        if ($topic === 'payment' && $queryId !== null && $queryId !== '') {
            return [
                'payment_id' => (string) $queryId,
                'external_reference' => null,
                'event' => 'payment.updated',
            ];
        }

        $data = $payload['data'] ?? null;
        $dataId = is_array($data) ? ($data['id'] ?? null) : null;
        if ($dataId !== null && $dataId !== '') {
            return [
                'payment_id' => (string) $dataId,
                'external_reference' => $this->extractExternalReference($payload),
                'event' => $this->inferEventFromPayload($payload),
            ];
        }

        $rootId = $payload['id'] ?? null;
        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        if ($rootId !== null && $rootId !== '' && ($type === 'payment' || $type === '')) {
            return [
                'payment_id' => (string) $rootId,
                'external_reference' => $this->extractExternalReference($payload),
                'event' => $this->inferEventFromPayload($payload),
            ];
        }

        return [
            'payment_id' => null,
            'external_reference' => null,
            'event' => 'payment.updated',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractExternalReference(array $payload): ?string
    {
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        $object = $data['object'] ?? $data;
        if (! is_array($object)) {
            return null;
        }

        $ref = $object['external_reference'] ?? null;
        if ($ref === null || $ref === '') {
            return null;
        }

        return (string) $ref;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function inferEventFromPayload(array $payload): string
    {
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        if ($action !== '') {
            return $action;
        }

        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        if ($type === 'payment') {
            return 'payment.updated';
        }

        return 'payment.updated';
    }

    /**
     * @param  array{payment_id: ?string, external_reference: ?string, event: string}  $parsed
     */
    private function looksLikePaymentNotification(array $parsed): bool
    {
        if ($parsed['payment_id'] === null) {
            return false;
        }

        $event = strtolower($parsed['event']);

        return str_contains($event, 'payment')
            || str_contains($event, 'paid')
            || $event === 'payment.updated'
            || $event === 'payment.created';
    }
}
