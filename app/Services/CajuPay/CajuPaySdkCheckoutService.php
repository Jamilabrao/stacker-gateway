<?php

namespace App\Services\CajuPay;

use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CajuPaySdkCheckoutService
{
    /**
     * @return array{token: string, checkout_session_id: string, raw: array<string, mixed>}
     */
    public function createCheckoutSession(Order $order, string $wallet, array $credentials): array
    {
        $public = trim((string) ($credentials['public_key'] ?? ''));
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($public === '' || $secret === '') {
            throw new \RuntimeException('CajuPay: credenciais incompletas.');
        }

        $amountCents = (int) round(((float) $order->amount) * 100);
        if ($amountCents < 1) {
            throw new \RuntimeException('CajuPay: valor inválido.');
        }

        $base = $this->baseUrl($credentials);
        $wallet = $this->normalizeWallet($wallet);

        $body = [
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'description' => 'Pedido #'.$order->id,
            'allow_pix' => false,
            'allow_card' => true,
            'allow_apple_pay' => $wallet === 'apple_pay',
            'allow_google_pay' => $wallet === 'google_pay',
        ];
        if ($wallet === 'card') {
            $body['allow_apple_pay'] = false;
            $body['allow_google_pay'] = false;
        }

        $idempotencyKey = Str::limit('getfy-caju-sdk-'.$order->id, 200, '');

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(25)
            ->withOptions(['connect_timeout' => 10])
            ->baseUrl($base)
            ->withHeaders([
                'X-API-Key' => $public,
                'X-API-Secret' => $secret,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post('/api/sdk/v1/checkout/sessions', $body);

        if (! $response->successful()) {
            $msg = $response->body();
            if (strlen($msg) > 400) {
                $msg = substr($msg, 0, 400).'…';
            }
            Log::warning('CajuPaySdkCheckoutService: create session failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException('CajuPay: '.($msg !== '' ? $msg : 'Erro ao criar sessão de checkout.'));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay: resposta inválida.');
        }

        $token = $data['token'] ?? null;
        $sessionId = $data['checkout_session_id'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($sessionId) || $sessionId === '') {
            throw new \RuntimeException('CajuPay: token ou checkout_session_id ausente.');
        }

        return [
            'token' => $token,
            'checkout_session_id' => $sessionId,
            'raw' => $data,
        ];
    }

    /**
     * GET público — sem secret. Retorna status normalizado ou null.
     */
    public function getPublicSessionStatus(string $publicToken, ?array $credentials = null): ?string
    {
        $publicToken = trim($publicToken);
        if ($publicToken === '') {
            return null;
        }

        $base = $this->baseUrl($credentials ?? []);

        $response = Http::acceptJson()
            ->timeout(20)
            ->withOptions(['connect_timeout' => 10])
            ->baseUrl($base)
            ->get('/api/sdk/public/checkout/sessions/'.$publicToken);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }

        return $this->mapPublicSessionStatus($data);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function baseUrl(array $credentials): string
    {
        $override = isset($credentials['base_url']) ? trim((string) $credentials['base_url']) : '';
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
    }

    private function normalizeWallet(string $wallet): string
    {
        $w = strtolower(trim($wallet));

        return in_array($w, ['card', 'apple_pay', 'google_pay'], true) ? $w : 'card';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapPublicSessionStatus(array $data): ?string
    {
        $status = $data['status'] ?? $data['payment_status'] ?? $data['state'] ?? null;
        if (! is_string($status) || trim($status) === '') {
            return 'pending';
        }
        $s = strtolower(trim($status));
        if (in_array($s, ['paid', 'completed', 'succeeded', 'success', 'confirmed', 'approved'], true)) {
            return 'paid';
        }
        if (in_array($s, ['failed', 'canceled', 'cancelled', 'rejected', 'declined'], true)) {
            return 'cancelled';
        }
        if (in_array($s, ['pending', 'processing', 'open', 'requires_action', 'awaiting_payment'], true)) {
            return 'pending';
        }

        return 'pending';
    }

    public static function resolveCredentialsForOrder(Order $order): ?array
    {
        $credential = GatewayCredential::resolveForPayment($order->tenant_id, 'cajupay');
        if (! $credential) {
            return null;
        }
        $credentials = $credential->getDecryptedCredentials();

        return $credentials === [] ? null : $credentials;
    }
}
