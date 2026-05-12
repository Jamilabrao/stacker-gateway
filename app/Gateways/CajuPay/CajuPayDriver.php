<?php

namespace App\Gateways\CajuPay;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CajuPayDriver implements GatewayDriver
{
    private function baseUrl(array $credentials): string
    {
        $override = isset($credentials['base_url']) ? trim((string) $credentials['base_url']) : '';
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function httpForCredentials(array $credentials): \Illuminate\Http\Client\PendingRequest
    {
        $public = trim((string) ($credentials['public_key'] ?? ''));
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($public === '' || $secret === '') {
            throw new \RuntimeException('CajuPay: informe a chave pública (X-API-Key) e a chave secreta (X-API-Secret) em Integrações > Gateways.');
        }

        $base = $this->baseUrl($credentials);

        return Http::acceptJson()
            ->asJson()
            ->timeout(25)
            ->withOptions(['connect_timeout' => 10])
            ->baseUrl($base)
            ->withHeaders([
                'X-API-Key' => $public,
                'X-API-Secret' => $secret,
            ]);
    }

    public function testConnection(array $credentials): bool
    {
        if (! $this->hasApiKeys($credentials)) {
            return false;
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/wallet/balance', ['kind' => 'main']);

            if ($response->successful()) {
                return true;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return false;
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver testConnection', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function hasApiKeys(array $credentials): bool
    {
        return trim((string) ($credentials['public_key'] ?? '')) !== ''
            && trim((string) ($credentials['secret_key'] ?? '')) !== '';
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        unset($postbackUrl);
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure a chave pública e a chave secreta da API (painel CajuPay → API / Chaves).');
        }

        $amountCents = (int) round($amount * 100);
        if ($amountCents < 1) {
            throw new \RuntimeException('CajuPay: valor inválido.');
        }

        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $name = $this->sanitizeName((string) ($consumer['name'] ?? ''));
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));

        $baseIdempotencyKey = Str::limit('getfy-order-'.$externalId, 200, '');

        $body = [
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'description' => 'Pedido #'.$externalId,
            'product_ref' => 'order-'.$externalId,
            'customer_ref' => 'getfy-order-'.$externalId,
            'consumer' => [
                'name' => $name,
                'email' => $email !== '' ? $email : 'cliente@checkout.local',
                'document' => $document,
            ],
        ];

        $response = $this->httpForCredentials($credentials)
            ->withHeaders(['Idempotency-Key' => $baseIdempotencyKey])
            ->post('/api/payments/pix', $body);

        // Alguns cenários reaproveitam o mesmo order id com payload levemente diferente.
        // Quando a API acusa mismatch de idempotência, tenta uma única vez com chave derivada do payload.
        if (! $response->successful() && str_contains(strtolower((string) $response->body()), 'idempotency_key_reuse_mismatch')) {
            $payloadHash = sha1(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $retryIdempotencyKey = Str::limit($baseIdempotencyKey.'-'.$payloadHash, 200, '');
            $response = $this->httpForCredentials($credentials)
                ->withHeaders(['Idempotency-Key' => $retryIdempotencyKey])
                ->post('/api/payments/pix', $body);
        }

        if (! $response->successful()) {
            $msg = $response->body();
            if (strlen($msg) > 300) {
                $msg = substr($msg, 0, 300).'…';
            }
            Log::warning('CajuPayDriver createPixPayment failed', [
                'status' => $response->status(),
                'order' => $externalId,
            ]);
            throw new \RuntimeException('CajuPay: '.($msg !== '' ? $msg : 'Erro ao criar cobrança PIX.'));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay: resposta inválida.');
        }

        $paymentId = $data['payment_id'] ?? '';
        if (! is_string($paymentId) || $paymentId === '') {
            throw new \RuntimeException('CajuPay: payment_id ausente na resposta.');
        }

        $qr = $data['pix_qr_code'] ?? null;
        $copy = $data['pix_copy_paste'] ?? null;

        return [
            'transaction_id' => $paymentId,
            'qrcode' => is_string($qr) ? $qr : null,
            'copy_paste' => is_string($copy) ? $copy : null,
            'raw' => $data,
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        if ($transactionId === '') {
            return null;
        }

        if ($this->looksLikeSdkSessionToken($transactionId)) {
            $sdkStatus = $this->getSdkSessionStatus($transactionId, $credentials);
            if ($sdkStatus !== null) {
                return $sdkStatus;
            }
        }

        if ($this->looksLikeUuid($transactionId)) {
            $sdkStatus = $this->getSdkSessionStatus($transactionId, $credentials);
            if ($sdkStatus !== null) {
                return $sdkStatus;
            }
        }

        if (! $this->hasApiKeys($credentials)) {
            return null;
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payments', ['limit' => 100]);

            if (! $response->successful()) {
                return null;
            }

            $list = $response->json();
            if (! is_array($list)) {
                return null;
            }

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $pid = $item['payment_id'] ?? null;
                if (! is_string($pid) || $pid !== $transactionId) {
                    continue;
                }

                return $this->normalizePaymentStatus($item['status'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getTransactionStatus', ['message' => $e->getMessage()]);

            return null;
        }

        return null;
    }

    private function looksLikeSdkSessionToken(string $value): bool
    {
        if (strlen($value) < 20) {
            return false;
        }
        if ($this->looksLikeUuid($value)) {
            return false;
        }

        return true;
    }

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    public function getSdkSessionStatus(string $token, array $credentials = []): ?string
    {
        if ($token === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withOptions(['connect_timeout' => 10])
                ->baseUrl($this->baseUrl($credentials))
                ->get('/api/sdk/public/checkout/sessions/'.urlencode($token));

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            $raw = $this->extractPublicSessionStatus($data);

            return $this->normalizePaymentStatus($raw);
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getSdkSessionStatus', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractPublicSessionStatus(array $data): mixed
    {
        foreach (['status', 'state', 'checkout_status', 'session_status', 'payment_status'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
        }

        foreach (['payment', 'latest_payment', 'charge', 'latest_charge'] as $nest) {
            $obj = $data[$nest] ?? null;
            if (! is_array($obj)) {
                continue;
            }
            foreach (['status', 'state'] as $key) {
                if (! array_key_exists($key, $obj)) {
                    continue;
                }
                $v = $obj[$key];
                if (is_string($v) && trim($v) !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<int, string>
     */
    public function getSessionAvailableMethods(string $token, array $credentials = []): array
    {
        if ($token === '') {
            return [];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withOptions(['connect_timeout' => 10])
                ->baseUrl($this->baseUrl($credentials))
                ->get('/api/sdk/public/checkout/sessions/'.urlencode($token));

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            $methods = $data['methods_available'] ?? ($data['available_methods'] ?? []);
            if (! is_array($methods)) {
                return [];
            }

            $normalized = [];
            foreach ($methods as $m) {
                $slug = strtolower(trim((string) $m));
                if ($slug === 'applepay') {
                    $slug = 'apple_pay';
                }
                if ($slug === 'googlepay') {
                    $slug = 'google_pay';
                }
                if (in_array($slug, ['card', 'boleto', 'pix', 'apple_pay', 'google_pay'], true)) {
                    $normalized[] = $slug;
                }
            }

            return array_values(array_unique($normalized));
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getSessionAvailableMethods', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<int, string>  $allowedMethods
     * @return array{token: string, checkout_session_id: string, raw: array<string, mixed>}
     */
    public function createSdkCheckoutSession(
        array $credentials,
        int $amountCents,
        string $description,
        string $externalId,
        array $consumer,
        array $allowedMethods,
        string $defaultMethod
    ): array {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure a chave pública e a chave secreta da API (painel CajuPay → API / Chaves).');
        }

        if ($amountCents < 1) {
            throw new \RuntimeException('CajuPay: valor inválido.');
        }

        $body = [
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'description' => $description !== '' ? $description : ('Pedido #'.$externalId),
            'allow_card' => in_array('card', $allowedMethods, true),
            'allow_boleto' => in_array('boleto', $allowedMethods, true),
            'allow_pix' => in_array('pix', $allowedMethods, true),
            'allow_apple_pay' => in_array('apple_pay', $allowedMethods, true),
            'allow_google_pay' => in_array('google_pay', $allowedMethods, true),
            'metadata' => [
                'external_id' => $externalId,
                'source' => 'getfy',
            ],
        ];

        $rawName = trim((string) ($consumer['name'] ?? ''));
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));
        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));

        $payer = array_filter([
            'name' => $rawName !== '' ? $this->sanitizeName($rawName) : null,
            'email' => $email !== '' ? $email : null,
            'document' => $document !== '' && $document !== '00000000000' ? $document : null,
        ], static fn ($v) => $v !== null && $v !== '');

        if (! empty($payer)) {
            $body['initial_payer'] = $payer;
        }

        if ($defaultMethod !== '') {
            $body['default_method'] = $defaultMethod;
        }

        $idempotencyKey = 'getfy-sdk-'.$externalId.'-'.Str::lower(Str::random(8));

        $response = $this->httpForCredentials($credentials)
            ->withHeaders(['Idempotency-Key' => Str::limit($idempotencyKey, 200, '')])
            ->post('/api/sdk/v1/checkout/sessions', $body);

        if (! $response->successful()) {
            $msg = $response->body();
            if (strlen($msg) > 300) {
                $msg = substr($msg, 0, 300).'…';
            }
            throw new \RuntimeException('CajuPay: '.($msg !== '' ? $msg : 'Erro ao criar sessão de checkout.'));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay: resposta inválida ao criar sessão.');
        }

        $token = $data['token'] ?? null;
        $sessionId = $data['checkout_session_id'] ?? ($data['id'] ?? null);

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('CajuPay: token ausente na resposta da sessão.');
        }
        if (! is_string($sessionId) || $sessionId === '') {
            throw new \RuntimeException('CajuPay: checkout_session_id ausente na resposta da sessão.');
        }

        return [
            'token' => $token,
            'checkout_session_id' => $sessionId,
            'raw' => $data,
        ];
    }

    private function normalizePaymentStatus(mixed $status): ?string
    {
        if (! is_string($status) || trim($status) === '') {
            return null;
        }
        $s = strtolower(trim($status));
        if (in_array($s, ['paid', 'completed', 'settled', 'approved', 'confirmed'], true)) {
            return 'paid';
        }
        if (in_array($s, ['pending', 'processing', 'waiting'], true)) {
            return 'pending';
        }
        if (in_array($s, ['cancelled', 'canceled', 'expired', 'failed', 'refunded'], true)) {
            return 'cancelled';
        }

        return $s;
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('CajuPay não suporta pagamento com cartão nesta integração.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('CajuPay não suporta boleto nesta integração.');
    }

    private function normalizeDocument(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document);
        $digits = is_string($digits) ? $digits : '';

        if (strlen($digits) === 11 || strlen($digits) === 14) {
            return $digits;
        }

        return '00000000000';
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: '';
        $name = trim($name);
        if ($name === '') {
            return 'Cliente';
        }
        if (strlen($name) > 120) {
            return substr($name, 0, 120);
        }

        return $name;
    }

    private function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = preg_replace('/[\x00-\x1F\x7F]/u', '', $email) ?: '';
        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
