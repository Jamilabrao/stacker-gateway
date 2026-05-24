<?php

namespace App\Gateways\CajuPay;

use App\Gateways\Contracts\GatewayDriver;
use App\Support\BrazilianDocuments;
use App\Support\CajuPayPaymentId;
use Illuminate\Http\Client\Response;
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

        $consumerPayload = [
            'name' => $name,
            'email' => $email !== '' ? $email : 'cliente@checkout.local',
            'document' => $document,
        ];
        $phoneDigits = preg_replace('/\D/', '', (string) ($consumer['phone'] ?? ''));
        if (is_string($phoneDigits) && strlen($phoneDigits) >= 10) {
            $consumerPayload['phone'] = $phoneDigits;
        }

        $body = [
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'description' => 'Pedido #'.$externalId,
            'product_ref' => 'order-'.$externalId,
            'customer_ref' => 'getfy-order-'.$externalId,
            'consumer' => $consumerPayload,
        ];

        $postbackUrl = trim($postbackUrl);
        if ($postbackUrl !== '') {
            $body['postback_url'] = $postbackUrl;
        }

        $response = $this->postPixCharge($credentials, $body, $baseIdempotencyKey);

        if (! $response->successful()) {
            Log::warning('CajuPayDriver createPixPayment failed', [
                'status' => $response->status(),
                'order' => $externalId,
            ]);
            throw new \RuntimeException($this->friendlyPixErrorMessage($response));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('Não foi possível gerar o PIX. Tente novamente.');
        }

        $paymentId = $data['payment_id'] ?? '';
        if (! is_string($paymentId) || $paymentId === '') {
            throw new \RuntimeException('Não foi possível gerar o PIX. Tente novamente.');
        }

        ['qrcode' => $qr, 'copy_paste' => $copy] = $this->extractPixFields($data);
        if ($qr === null && $copy === null) {
            Log::warning('CajuPayDriver createPixPayment missing pix payload', [
                'order' => $externalId,
                'payment_id' => $paymentId,
            ]);
            throw new \RuntimeException('PIX criado sem código de pagamento. Tente novamente.');
        }

        return [
            'transaction_id' => $paymentId,
            'qrcode' => $qr,
            'copy_paste' => $copy,
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     */
    private function postPixCharge(array $credentials, array $body, string $idempotencyKey): Response
    {
        $http = $this->httpForCredentials($credentials)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey]);

        try {
            $response = $http->post('/api/payments/pix', $body);
        } catch (\Throwable $e) {
            Log::warning('CajuPayDriver createPixPayment transport error', [
                'message' => $e->getMessage(),
            ]);
            usleep(400_000);
            $response = $http->post('/api/payments/pix', $body);
        }

        if (! $response->successful() && str_contains(strtolower((string) $response->body()), 'idempotency_key_reuse_mismatch')) {
            $payloadHash = sha1(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $retryKey = Str::limit($idempotencyKey.'-'.$payloadHash, 200, '');
            $response = $this->httpForCredentials($credentials)
                ->withHeaders(['Idempotency-Key' => $retryKey])
                ->post('/api/payments/pix', $body);
        }

        if (! $response->successful() && $this->isRetryableHttpStatus($response->status())) {
            usleep(400_000);
            $response = $this->httpForCredentials($credentials)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/api/payments/pix', $body);
        }

        return $response;
    }

    private function isRetryableHttpStatus(int $status): bool
    {
        return in_array($status, [408, 429, 500, 502, 503, 504], true);
    }

    private function friendlyPixErrorMessage(Response $response): string
    {
        $parsed = $this->parseApiErrorMessage($response);
        if ($parsed !== '') {
            if (str_contains(strtolower($parsed), 'document') || str_contains(strtolower($parsed), 'cpf')) {
                return 'CPF ou CNPJ inválido. Verifique os dados e tente novamente.';
            }

            return $parsed;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return 'Pagamento PIX indisponível no momento. O vendedor deve verificar as chaves da API CajuPay.';
        }

        if ($this->isRetryableHttpStatus($response->status())) {
            return 'Serviço PIX temporariamente indisponível. Aguarde alguns segundos e tente novamente.';
        }

        return 'Não foi possível gerar o PIX. Tente novamente.';
    }

    private function parseApiErrorMessage(Response $response): string
    {
        $data = $response->json();
        if (! is_array($data)) {
            return '';
        }

        foreach (['message', 'error', 'detail', 'title'] as $key) {
            $v = $data[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        $errors = $data['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($errors as $err) {
                if (is_string($err) && trim($err) !== '') {
                    return trim($err);
                }
                if (is_array($err)) {
                    $msg = $err['message'] ?? $err['detail'] ?? null;
                    if (is_string($msg) && trim($msg) !== '') {
                        return trim($msg);
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{qrcode: ?string, copy_paste: ?string}
     */
    private function extractPixFields(array $data): array
    {
        $qr = $this->firstNonEmptyString($data, [
            'pix_qr_code', 'qr_code', 'qrcode', 'qrcode_image', 'qr_code_base64',
        ]);
        $copy = $this->firstNonEmptyString($data, [
            'pix_copy_paste', 'copy_paste', 'emv', 'brcode', 'pix_code', 'payload',
        ]);

        $nested = $data['pix'] ?? $data['payment'] ?? null;
        if (is_array($nested)) {
            $qr = $qr ?? $this->firstNonEmptyString($nested, [
                'pix_qr_code', 'qr_code', 'qrcode', 'qrcode_image',
            ]);
            $copy = $copy ?? $this->firstNonEmptyString($nested, [
                'pix_copy_paste', 'copy_paste', 'emv', 'brcode',
            ]);
        }

        return ['qrcode' => $qr, 'copy_paste' => $copy];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstNonEmptyString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $v = $data[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
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

        return $this->getPixPaymentStatus($transactionId, $credentials);
    }

    /**
     * Consulta status de cobrança PIX/API por payment_id (não sessão SDK).
     */
    public function getPixPaymentStatus(string $paymentId, array $credentials): ?string
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '' || ! $this->hasApiKeys($credentials)) {
            return null;
        }

        $fromDirect = $this->fetchPixPaymentStatusById($paymentId, $credentials);
        if ($fromDirect !== null) {
            return $fromDirect;
        }

        $fromFilter = $this->fetchPixPaymentStatusByListFilter($paymentId, $credentials);
        if ($fromFilter !== null) {
            return $fromFilter;
        }

        return $this->fetchPixPaymentStatusFromList($paymentId, $credentials);
    }

    private function fetchPixPaymentStatusById(string $paymentId, array $credentials): ?string
    {
        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payments/'.rawurlencode($paymentId));

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            if (isset($data['payment_id']) || isset($data['status'])) {
                return $this->normalizePaymentStatus($data['status'] ?? null);
            }

            $nested = $data['payment'] ?? $data['data'] ?? null;
            if (is_array($nested)) {
                return $this->normalizePaymentStatus($nested['status'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver fetchPixPaymentStatusById', ['message' => $e->getMessage()]);
        }

        return null;
    }

    private function fetchPixPaymentStatusByListFilter(string $paymentId, array $credentials): ?string
    {
        foreach (['payment_id', 'id'] as $param) {
            try {
                $response = $this->httpForCredentials($credentials)
                    ->get('/api/payments', [
                        $param => $paymentId,
                        'limit' => 10,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $status = $this->findPaymentStatusInList($response->json(), $paymentId, 'payment_id');
                if ($status !== null) {
                    return $status;
                }
            } catch (\Throwable $e) {
                Log::debug('CajuPayDriver fetchPixPaymentStatusByListFilter', [
                    'param' => $param,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function fetchPixPaymentStatusFromList(string $paymentId, array $credentials): ?string
    {
        $perPage = 100;
        $maxPages = 5;

        try {
            for ($page = 1; $page <= $maxPages; $page++) {
                $query = ['limit' => $perPage];
                if ($page > 1) {
                    $query['page'] = $page;
                }

                $response = $this->httpForCredentials($credentials)->get('/api/payments', $query);
                if (! $response->successful()) {
                    break;
                }

                $status = $this->findPaymentStatusInList($response->json(), $paymentId, 'payment_id');
                if ($status !== null) {
                    return $status;
                }

                $items = $this->normalizePaymentsList($response->json());
                if (count($items) < $perPage) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver fetchPixPaymentStatusFromList', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizePaymentsList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        if (isset($list['items']) && is_array($list['items'])) {
            $list = $list['items'];
        } elseif (isset($list['data']) && is_array($list['data'])) {
            $list = $list['data'];
        }

        return array_values(array_filter($list, static fn ($it) => is_array($it)));
    }

    /**
     * @param  'payment_id'|'customer_ref'  $matchField
     */
    private function findPaymentStatusInList(mixed $list, string $needle, string $matchField): ?string
    {
        foreach ($this->normalizePaymentsList($list) as $item) {
            $value = $item[$matchField] ?? null;
            if (! is_string($value) || $value !== $needle) {
                continue;
            }

            return $this->normalizePaymentStatus($item['status'] ?? null);
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

    /**
     * Estorno PIX via API CajuPay (módulo 18). Cartão/wallet: sem API — use webhook.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{success: bool, pending?: bool, message?: string, error_code?: string, raw?: array<string, mixed>}
     */
    public function refundTransaction(array $credentials, string $txId, float $amount, string $externalId): array
    {
        if (! $this->hasApiKeys($credentials)) {
            return ['success' => false, 'message' => 'Credenciais CajuPay não configuradas.'];
        }

        $paymentId = trim($txId);
        if ($paymentId === '' || ! CajuPayPaymentId::looksLikeUuid($paymentId)) {
            return ['success' => false, 'message' => 'ID de pagamento CajuPay inválido para reembolso PIX.'];
        }

        $clientRefundId = $this->normalizeClientRefundId('order-'.$externalId.'-refund');

        $payload = ['client_refund_id' => $clientRefundId];
        $response = $this->httpForCredentials($credentials)
            ->post('/api/payments/'.rawurlencode($paymentId).'/pix-refund', $payload);

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => $this->friendlyRefundErrorMessage($response),
                'error_code' => $this->parseApiErrorCode($response),
            ];
        }

        $data = $response->json();
        $body = is_array($data) ? $data : [];

        return $this->mapPixRefundResponse($body);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function createPixRefund(array $credentials, string $paymentId, ?string $clientRefundId = null): array
    {
        $paymentId = trim($paymentId);
        $payload = [];
        if ($clientRefundId !== null && $clientRefundId !== '') {
            $payload['client_refund_id'] = $this->normalizeClientRefundId($clientRefundId);
        }

        $response = $this->httpForCredentials($credentials)
            ->post('/api/payments/'.rawurlencode($paymentId).'/pix-refund', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException($this->friendlyRefundErrorMessage($response));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getPixRefund(array $credentials, string $paymentId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/payments/'.rawurlencode(trim($paymentId)).'/pix-refund');

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw new \RuntimeException($this->friendlyRefundErrorMessage($response));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function retryPixRefund(array $credentials, string $paymentId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->post('/api/payments/'.rawurlencode(trim($paymentId)).'/pix-refund/retry');

        if (! $response->successful()) {
            throw new \RuntimeException($this->friendlyRefundErrorMessage($response));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function cancelPixRefund(array $credentials, string $paymentId): bool
    {
        $response = $this->httpForCredentials($credentials)
            ->delete('/api/payments/'.rawurlencode(trim($paymentId)).'/pix-refund');

        return $response->status() === 204 || $response->successful();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{success: bool, pending?: bool, message?: string, error_code?: string, raw?: array<string, mixed>}
     */
    public function mapPixRefundResponse(array $body): array
    {
        $status = strtolower(trim((string) ($body['status'] ?? '')));

        if ($status === 'devolvido') {
            return ['success' => true, 'pending' => false, 'message' => 'Reembolso PIX confirmado.', 'raw' => $body];
        }

        if (in_array($status, ['submitted', 'pending_balance'], true)) {
            return ['success' => true, 'pending' => true, 'message' => 'Reembolso PIX enviado; aguardando confirmação.', 'raw' => $body];
        }

        if ($status === 'failed') {
            $err = trim((string) ($body['last_error'] ?? 'Falha no reembolso PIX.'));

            return ['success' => false, 'message' => $err !== '' ? $err : 'Falha no reembolso PIX.', 'raw' => $body];
        }

        return ['success' => true, 'pending' => true, 'message' => 'Reembolso PIX em processamento.', 'raw' => $body];
    }

    private function normalizeClientRefundId(string $id): string
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '-', $id) ?: 'refund';

        return Str::limit($id, 64, '');
    }

    private function parseApiErrorCode(Response $response): ?string
    {
        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }
        $error = $data['error'] ?? null;
        if (! is_string($error) || trim($error) === '') {
            return null;
        }

        return strtolower(trim($error));
    }

    private function friendlyRefundErrorMessage(Response $response): string
    {
        $parsed = $this->parseApiErrorMessage($response);
        $code = $this->parseApiErrorCode($response) ?? '';

        $map = [
            'med_blocks_refund' => 'Reembolso bloqueado: existe disputa MED aberta neste pagamento.',
            'refund_window_expired' => 'Prazo de reembolso PIX expirado (30 dias).',
            'payment_not_paid' => 'Pagamento ainda não está confirmado como pago.',
            'payment_not_found' => 'Pagamento não encontrado na CajuPay.',
            'refund_not_found' => 'Nenhum pedido de reembolso encontrado para este pagamento.',
            'invalid_client_refund_id' => 'Identificador de reembolso inválido.',
            'missing_pix_end_to_end_id' => 'Identificador PIX end-to-end ausente no provedor.',
            'refund_only_onlyup' => 'Conta não habilitada para reembolso via API.',
        ];

        if ($code !== '' && isset($map[$code])) {
            return $map[$code];
        }

        if ($parsed !== '') {
            return $parsed;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return 'Credenciais CajuPay inválidas ou sem permissão payments.write.';
        }

        return 'Não foi possível processar o reembolso PIX na CajuPay.';
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{data: list<array<string, mixed>>}
     */
    public function listMedDisputes(array $credentials, int $limit = 50): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/med', ['limit' => max(1, min(100, $limit))]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->parseApiErrorMessage($response) ?: 'Falha ao listar disputas MED.');
        }

        $data = $response->json();

        return is_array($data) ? $data : ['data' => []];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getMedDispute(array $credentials, string $disputeId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/med/'.rawurlencode(trim($disputeId)));

        if ($response->status() === 404) {
            throw new \RuntimeException('Disputa MED não encontrada.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException($this->parseApiErrorMessage($response) ?: 'Falha ao consultar disputa MED.');
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getMedSummary(array $credentials): int
    {
        $response = $this->httpForCredentials($credentials)->get('/api/med/summary');
        if (! $response->successful()) {
            return 0;
        }
        $data = $response->json();
        if (! is_array($data)) {
            return 0;
        }

        return (int) ($data['open_count'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  list<\Illuminate\Http\UploadedFile>  $attachments
     * @return array<string, mixed>
     */
    public function submitMedDefense(array $credentials, string $disputeId, string $text, array $attachments = []): array
    {
        $disputeId = trim($disputeId);
        $http = $this->httpForCredentials($credentials);

        $request = $http->asMultipart();
        foreach ($attachments as $file) {
            $request = $request->attach(
                'attachments[]',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            );
        }

        $response = $request->post('/api/med/'.rawurlencode($disputeId).'/defense', [
            'text' => $text,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->parseApiErrorMessage($response) ?: 'Falha ao enviar defesa MED.');
        }

        $data = $response->json();

        return is_array($data) ? $data : ['ok' => true];
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

    /**
     * @return list<array<string, mixed>>
     */
    public function listWebhookEndpoints(array $credentials): array
    {
        if (! $this->hasApiKeys($credentials)) {
            return [];
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/webhooks/endpoints');

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            if (isset($data['items']) && is_array($data['items'])) {
                $data = $data['items'];
            }

            return array_values(array_filter($data, static fn ($it) => is_array($it)));
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver listWebhookEndpoints', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array{endpoint_id: string, signing_secret: string|null, raw: array<string, mixed>}
     */
    public function registerWebhookEndpoint(array $credentials, string $url, ?string $existingId = null): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API antes de registrar o webhook.');
        }
        if ($url === '') {
            throw new \RuntimeException('CajuPay: URL do webhook vazia.');
        }

        $eventTypes = [
            'payment.paid',
            'payment.failed',
            'payment.refunded',
            'checkout.payment.paid',
            'checkout.payment.failed',
            'checkout.payment.refunded',
            'checkout.payment.disputed',
            'card.payment.succeeded',
            'card.payment.failed',
            'card.payment.refunded',
            'card.payment.disputed',
            'pix.payment.refunded',
            'pix.payment.med_opened',
            'pix.payment.med_resolved',
        ];

        $http = $this->httpForCredentials($credentials);

        try {
            if ($existingId !== null && $existingId !== '') {
                $response = $http->patch('/api/webhooks/endpoints', [
                    'id' => $existingId,
                    'url' => $url,
                    'enabled' => true,
                    'rotate_secret' => true,
                ]);
            } else {
                $response = $http->post('/api/webhooks/endpoints', [
                    'url' => $url,
                    'description' => 'Getfy ('.parse_url($url, PHP_URL_HOST).')',
                    'event_types' => $eventTypes,
                ]);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('CajuPay: falha ao contatar o registro de webhooks: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $msg = $response->body();
            if (strlen($msg) > 300) {
                $msg = substr($msg, 0, 300).'…';
            }
            throw new \RuntimeException('CajuPay: '.($msg !== '' ? $msg : 'Erro ao registrar webhook.'));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay: resposta inválida ao registrar webhook.');
        }

        $endpointId = $data['id'] ?? ($existingId ?? null);
        $signingSecret = $data['signing_secret'] ?? null;

        if (! is_string($endpointId) || $endpointId === '') {
            throw new \RuntimeException('CajuPay: endpoint_id ausente na resposta de webhook.');
        }

        return [
            'endpoint_id' => $endpointId,
            'signing_secret' => is_string($signingSecret) && $signingSecret !== '' ? $signingSecret : null,
            'raw' => $data,
        ];
    }

    private function normalizeDocument(string $document): string
    {
        $digits = BrazilianDocuments::digits($document);

        if (strlen($digits) === 11 && BrazilianDocuments::isValidCpf($digits)) {
            return $digits;
        }
        if (strlen($digits) === 14 && BrazilianDocuments::isValidCnpj($digits)) {
            return $digits;
        }
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
