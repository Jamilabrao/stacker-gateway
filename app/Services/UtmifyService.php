<?php

namespace App\Services;

use App\Models\CheckoutSession;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class UtmifyService
{
    private const ENDPOINT = 'https://api.utmify.com.br/api-credentials/orders';

    /**
     * Build payload and send order to UTMIFY API.
     *
     * @param  array{approved_at?: string|null, refunded_at?: string|null}  $options
     */
    public function sendOrder(
        Order $order,
        string $utmifyStatus,
        string $apiKey,
        array $options = []
    ): void {
        $body = $this->buildPayload($order, $utmifyStatus, $options);
        $this->post($apiKey, $body);
    }

    /**
     * Send a test order to UTMIFY API.
     */
    public function sendTest(string $apiKey): void
    {
        $this->post($apiKey, $this->buildTestPayload());
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTestPayload(): array
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $platform = config('getfy.app_name', 'Getfy');

        return [
            'orderId' => 'test-'.Str::uuid()->toString(),
            'platform' => $platform,
            'paymentMethod' => 'pix',
            'status' => 'paid',
            'isTest' => true,
            'createdAt' => $now,
            'approvedDate' => $now,
            'refundedAt' => null,
            'customer' => [
                'name' => 'Cliente Exemplo',
                'email' => 'exemplo@email.com',
                'phone' => '11999999999',
                'document' => '12345678900',
                'country' => 'BR',
                'ip' => '',
            ],
            'products' => [
                [
                    'id' => 'test-product',
                    'name' => 'Produto de teste',
                    'planId' => null,
                    'planName' => null,
                    'quantity' => 1,
                    'priceInCents' => 1000,
                ],
            ],
            'trackingParameters' => $this->buildTrackingParameters(null, [
                'utm_source' => 'test',
                'utm_medium' => 'test',
                'utm_campaign' => 'test',
            ]),
            'commission' => [
                'totalPriceInCents' => 1000,
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => 1000,
            ],
        ];
    }

    /**
     * @param  array{approved_at?: string|null, refunded_at?: string|null, is_test?: bool}  $options
     * @return array<string, mixed>
     */
    public function buildPayload(Order $order, string $utmifyStatus, array $options = []): array
    {
        $order->loadMissing(['user', 'orderItems.product', 'orderItems.productOffer', 'orderItems.subscriptionPlan']);

        $session = CheckoutSession::where('order_id', $order->id)->first();
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $orderId = $order->gateway_id ?: (string) $order->id;
        $paymentMethod = $this->mapPaymentMethod($order);
        $createdAt = $order->created_at->utc()->format('Y-m-d H:i:s');
        $approvedDate = $options['approved_at'] ?? ($utmifyStatus === 'paid' ? $order->updated_at->utc()->format('Y-m-d H:i:s') : null);
        $refundedAt = $options['refunded_at'] ?? null;

        $customerName = $session?->name ?? $order->user?->name ?? '';
        $customer = [
            'name' => $customerName,
            'email' => $order->email ?? '',
            'phone' => $order->phone ?? '',
            'document' => $order->cpf ?? '',
            'country' => 'BR',
            'ip' => $order->customer_ip ?? '',
        ];

        $products = [];
        foreach ($order->orderItems as $item) {
            $product = $item->product;
            $planId = $item->product_offer_id ?? $item->subscription_plan_id;
            $planName = null;
            if ($item->productOffer) {
                $planName = $item->productOffer->name;
            } elseif ($item->subscriptionPlan) {
                $planName = $item->subscriptionPlan->name;
            }
            $products[] = [
                'id' => (string) ($product?->id ?? $item->product_id ?? $item->id),
                'name' => $product?->name ?? 'Produto',
                'planId' => $planId ? (string) $planId : null,
                'planName' => $planName,
                'quantity' => 1,
                'priceInCents' => (int) round((float) $item->amount * 100),
            ];
        }

        if (empty($products)) {
            $mainProduct = $order->product;
            $products[] = [
                'id' => (string) ($mainProduct?->id ?? $order->product_id),
                'name' => $mainProduct?->name ?? 'Produto',
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => (int) round((float) $order->amount * 100),
            ];
        }

        $trackingParameters = $this->buildTrackingParameters($session, $meta);

        $totalCents = (int) round((float) $order->amount * 100);
        $commission = [
            'totalPriceInCents' => $totalCents,
            'gatewayFeeInCents' => 0,
            'userCommissionInCents' => $totalCents,
        ];

        $body = [
            'orderId' => $orderId,
            'platform' => config('getfy.app_name', 'Getfy'),
            'paymentMethod' => $paymentMethod,
            'status' => $utmifyStatus,
            'createdAt' => $createdAt,
            'approvedDate' => $approvedDate,
            'refundedAt' => $refundedAt,
            'customer' => $customer,
            'products' => $products,
            'trackingParameters' => $trackingParameters,
            'commission' => $commission,
        ];

        if (! empty($options['is_test'])) {
            $body['isTest'] = true;
        }

        return $body;
    }

    /**
     * UTMIFY exige utm_content e utm_term sempre presentes (string ou null).
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, string|null>
     */
    private function buildTrackingParameters(?CheckoutSession $session, array $meta): array
    {
        $trackingParameters = [];

        foreach (['src', 'sck', 'utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            $raw = $session?->{$key} ?? ($meta[$key] ?? null);
            if (! is_string($raw)) {
                continue;
            }
            $trimmed = trim($raw);
            if ($trimmed !== '') {
                $trackingParameters[$key] = $trimmed;
            }
        }

        foreach (['utm_content', 'utm_term'] as $key) {
            $raw = $session?->{$key} ?? ($meta[$key] ?? null);
            if (is_string($raw)) {
                $trimmed = trim($raw);

                $trackingParameters[$key] = $trimmed !== '' ? $trimmed : null;
            } else {
                $trackingParameters[$key] = null;
            }
        }

        return $trackingParameters;
    }

    /**
     * POST to UTMIFY API. Throws on failure.
     */
    public function post(string $apiKey, array $body): \Illuminate\Http\Client\Response
    {
        $response = Http::timeout(15)
            ->withHeaders(['x-api-token' => $apiKey])
            ->post(self::ENDPOINT, $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'UTMIFY API error: '.$response->status().' '.$response->body()
            );
        }

        return $response;
    }

    private function mapPaymentMethod(Order $order): string
    {
        $key = $order->resolveCheckoutPaymentMethodKey();
        if ($key !== null) {
            return match ($key) {
                'pix', 'pix_auto' => 'pix',
                'boleto' => 'boleto',
                'card', 'apple_pay', 'google_pay' => 'credit_card',
                default => $this->mapPaymentMethodFromGateway($order->gateway),
            };
        }

        return $this->mapPaymentMethodFromGateway($order->gateway);
    }

    private function mapPaymentMethodFromGateway(?string $gateway): string
    {
        if (! $gateway) {
            return 'pix';
        }
        $g = strtolower($gateway);
        if (str_contains($g, 'pix')) {
            return 'pix';
        }
        if (str_contains($g, 'boleto') || str_contains($g, 'ticket')) {
            return 'boleto';
        }
        if (str_contains($g, 'card') || str_contains($g, 'credit') || str_contains($g, 'cartao')) {
            return 'credit_card';
        }

        return 'pix';
    }
}
