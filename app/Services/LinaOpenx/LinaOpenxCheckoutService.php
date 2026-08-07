<?php

namespace App\Services\LinaOpenx;

use App\Gateways\GatewayRegistry;
use App\Gateways\LinaOpenx\LinaOpenxDriver;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\CheckoutPaymentConsumer;
use App\Support\GatewayPaymentCredentials;
use App\Support\GatewayPluginRequirement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class LinaOpenxCheckoutService
{
    public const GATEWAY_SLUG = 'linaopenx';

    public function __construct(
        private readonly LinaOpenxClient $client = new LinaOpenxClient()
    ) {}

    /**
     * Inicia pagamento Open Finance (white label) para o pedido.
     *
     * @param  array{name?: string, document?: string, email?: string}|array<string, mixed>  $validated
     * @return array{transaction_id: string, redirect_url: string, return_url: string}
     */
    public function startPaymentForOrder(Order $order, array $validated, string $description = ''): array
    {
        GatewayPluginRequirement::assertUnlocked(self::GATEWAY_SLUG);

        $credentials = $this->credentialsForOrder($order);
        if ($credentials === null) {
            throw new \RuntimeException('Lina OpenX não configurada. Conecte o adquirente no painel.');
        }

        $consumer = CheckoutPaymentConsumer::build($validated, $order->id);
        $document = preg_replace('/\D+/', '', (string) ($consumer['document'] ?? $order->cpf ?? '')) ?? '';
        if ($document === '') {
            throw new \RuntimeException('CPF/CNPJ é obrigatório para pagar com Open Finance.');
        }
        $consumer['document'] = $document;

        $returnUrl = $this->signedReturnUrl($order);
        $driver = GatewayRegistry::driver(self::GATEWAY_SLUG);
        if (! $driver instanceof LinaOpenxDriver) {
            throw new \RuntimeException('Driver Lina OpenX indisponível.');
        }

        $result = $driver->createOpenFinancePayment(
            $credentials,
            (float) $order->amount,
            $consumer,
            (string) $order->id,
            $returnUrl,
            [
                'description' => $description !== '' ? $description : ('Pedido #'.($order->public_reference ?: $order->id)),
                'metadata' => [
                    'order_id' => $order->id,
                    'public_reference' => $order->public_reference,
                    'tenant_id' => $order->tenant_id,
                ],
            ]
        );

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['lina_payment_request_id'] = $result['transaction_id'];
        $meta['checkout_payment_method'] = 'open_finance';
        $meta['lina_return_url'] = $returnUrl;

        $order->update([
            'gateway' => self::GATEWAY_SLUG,
            'gateway_id' => $result['transaction_id'],
            'payment_method' => 'open_finance',
            'metadata' => $meta,
        ]);

        return [
            'transaction_id' => $result['transaction_id'],
            'redirect_url' => $result['redirect_url'],
            'return_url' => $returnUrl,
        ];
    }

    /**
     * Após o return do portal: reconsulta API e tenta concluir o pedido se pago.
     *
     * @return array{order: Order, status: string, paid: bool}
     */
    public function handleReturn(Order $order, ?string $paymentLinkId = null): array
    {
        $order = $order->fresh() ?? $order;
        if ($paymentLinkId !== null && trim($paymentLinkId) !== '') {
            $linkId = trim($paymentLinkId);
            $meta = is_array($order->metadata) ? $order->metadata : [];
            if (($order->gateway_id === null || $order->gateway_id === '') || $order->gateway_id !== $linkId) {
                $meta['lina_payment_request_id'] = $linkId;
                $order->update([
                    'gateway' => self::GATEWAY_SLUG,
                    'gateway_id' => $linkId,
                    'metadata' => $meta,
                ]);
                $order->refresh();
            }
        }

        $status = $this->resolveNormalizedStatus($order);
        if ($status === 'paid' && $order->status === 'pending') {
            $this->dispatchPaid($order);
            $order->refresh();
        }

        return [
            'order' => $order,
            'status' => $status,
            'paid' => $order->status === 'completed' || $status === 'paid',
        ];
    }

    /**
     * Poll / webhook: reconsulta e conclui se pago.
     */
    public function tryCompleteFromApi(Order $order): Order
    {
        $order = $order->fresh() ?? $order;
        if ($order->status !== 'pending') {
            return $order;
        }

        $status = $this->resolveNormalizedStatus($order);
        if ($status === 'paid') {
            $this->dispatchPaid($order);
            $order->refresh();
        }

        return $order;
    }

    public function resolveNormalizedStatus(Order $order): string
    {
        $requestId = trim((string) ($order->gateway_id ?? ''));
        if ($requestId === '') {
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $requestId = trim((string) ($meta['lina_payment_request_id'] ?? ''));
        }
        if ($requestId === '') {
            return 'pending';
        }

        $credentials = $this->credentialsForOrder($order);
        if ($credentials === null) {
            return 'pending';
        }

        try {
            $result = $this->client->getPaymentRequest($credentials, $requestId);

            return $result['status'] ?? 'pending';
        } catch (\Throwable $e) {
            Log::debug('LinaOpenxCheckoutService: status lookup failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return 'pending';
        }
    }

    public function signedReturnUrl(Order $order): string
    {
        return URL::temporarySignedRoute(
            'checkout.lina.return',
            now()->addHours(2),
            ['order' => $order->id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credentialsForOrder(Order $order): ?array
    {
        $resolved = GatewayPaymentCredentials::resolve($order->tenant_id, self::GATEWAY_SLUG, $order);
        if ($resolved !== null && $resolved !== []) {
            return $resolved;
        }

        $credential = GatewayCredential::resolveForPayment($order->tenant_id, self::GATEWAY_SLUG);
        if ($credential === null) {
            return null;
        }

        $credentials = $credential->getDecryptedCredentials();

        return is_array($credentials) && $credentials !== [] ? $credentials : null;
    }

    private function dispatchPaid(Order $order): void
    {
        $txId = trim((string) ($order->gateway_id ?? ''));
        if ($txId === '') {
            return;
        }

        ProcessPaymentWebhook::dispatchSync(
            self::GATEWAY_SLUG,
            $txId,
            'order.paid',
            'paid',
            [
                'source' => 'linaopenx_return_or_poll',
                'webhook_source' => 'linaopenx_api_reconfirm',
            ]
        );
    }
}
