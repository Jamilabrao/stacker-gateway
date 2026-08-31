<?php

namespace App\Gateways\Cielo;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Log;

class CieloDriver implements GatewayDriver
{
    public function testConnection(array $credentials): bool
    {
        if (CieloHttpClient::merchantId($credentials) === '' || CieloHttpClient::merchantKey($credentials) === '') {
            return false;
        }

        try {
            $url = CieloHttpClient::queryBase($credentials).'/1/sales?merchantOrderId=cielo-ping';
            $response = CieloHttpClient::send($credentials, 'GET', $url, null, 15);
            if (in_array($response->status(), [401, 403], true)) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::debug('CieloDriver testConnection', ['message' => $e->getMessage()]);

            return false;
        }
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $amountCents = $this->amountToCents($amount);
        $expiration = (int) ($options['expiration'] ?? 86400);
        $expiration = max(60, min(86400, $expiration));

        $body = [
            'MerchantOrderId' => $this->merchantOrderId($externalId, 50),
            'Customer' => $this->buildCustomer($consumer, true),
            'Payment' => [
                'Type' => 'Pix',
                'Provider' => 'Cielo2',
                'Amount' => $amountCents,
                'QrCode' => [
                    'Expiration' => $expiration,
                ],
            ],
        ];

        // Cielo2 PIX não tem sandbox: cartão pode usar sandbox, PIX sempre produção.
        $url = CieloHttpClient::transactionalBase($credentials, true).'/1/sales/';
        $response = CieloHttpClient::send($credentials, 'POST', $url, $body);

        if (! $response->successful()) {
            $this->throwFromResponse($response, 'Não foi possível gerar o PIX.');
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $payment = is_array($data['Payment'] ?? null) ? $data['Payment'] : [];
        $qrNode = is_array($payment['QrCode'] ?? null) ? $payment['QrCode'] : [];
        $paymentId = $this->firstNonEmptyString($payment, ['PaymentId', 'Paymentid', 'paymentId']);
        if ($paymentId === '') {
            throw new \RuntimeException('Cielo: resposta PIX sem PaymentId.');
        }

        $copy = $this->firstNonEmptyString($payment, ['QrCodeString', 'QrcodeString', 'Emv']);
        if ($copy === '') {
            $copy = $this->firstNonEmptyString($qrNode, ['String', 'Emv', 'QrCodeString']);
        }
        $qr = $this->firstNonEmptyString($payment, ['QrCodeBase64Image', 'QrcodeBase64Image']);
        if ($qr === '') {
            $qr = $this->firstNonEmptyString($qrNode, ['Base64Image', 'QrcodeBase64Image', 'QrCodeBase64Image']);
        }
        $qr = $this->normalizeQrImage($qr);

        if ($copy === '' && $qr === '') {
            throw new \RuntimeException('Cielo: PIX gerado sem QR Code ou copia e cola.');
        }

        return [
            'transaction_id' => $paymentId,
            'qrcode' => $qr !== '' ? $qr : null,
            'copy_paste' => $copy !== '' ? $copy : null,
            'raw' => $data,
            'metadata' => array_filter([
                'cielo_txid' => isset($payment['SentOrderId']) ? (string) $payment['SentOrderId'] : null,
                'cielo_merchant_order_id' => (string) ($data['MerchantOrderId'] ?? $this->merchantOrderId($externalId)),
                'cielo_provider' => 'Cielo2',
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $sale = $this->getSale($transactionId, $credentials);
        if ($sale === null) {
            return null;
        }

        $status = $sale['Payment']['Status'] ?? null;
        if (! is_numeric($status)) {
            return null;
        }

        return $this->mapPaymentStatus((int) $status);
    }

    /**
     * Consulta completa na API de query (fonte de verdade do webhook).
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    public function getSale(string $transactionId, array $credentials): ?array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        $bases = [CieloHttpClient::queryBase($credentials)];
        if (CieloHttpClient::isSandbox($credentials)) {
            $bases[] = rtrim(CieloHttpClient::QUERY_PRODUCTION, '/');
        }

        foreach (array_unique($bases) as $base) {
            try {
                $url = $base.'/1/sales/'.rawurlencode($transactionId);
                $response = CieloHttpClient::send($credentials, 'GET', $url);
                if ($response->status() === 404) {
                    continue;
                }
                if (! $response->successful()) {
                    Log::debug('CieloDriver getSale HTTP', [
                        'transaction_id' => $transactionId,
                        'status' => $response->status(),
                    ]);

                    continue;
                }
                $data = $response->json();

                return is_array($data) ? $data : null;
            } catch (\Throwable $e) {
                Log::debug('CieloDriver getSale', [
                    'transaction_id' => $transactionId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $parsed = $this->parseCardPayload($card);
        if ($parsed['payment_token'] === '') {
            throw new \RuntimeException('Cielo: token do cartão inválido. Preencha novamente.');
        }

        $amountCents = $this->amountToCents($amount);
        $installments = $parsed['installments'];
        $creditCard = [
            'PaymentToken' => $parsed['payment_token'],
        ];
        if ($parsed['brand'] !== '') {
            $creditCard['Brand'] = $parsed['brand'];
        }

        $body = [
            'MerchantOrderId' => $this->merchantOrderId($externalId),
            'Customer' => $this->buildCustomer($consumer),
            'Payment' => [
                'Type' => 'CreditCard',
                'Amount' => $amountCents,
                'Installments' => $installments,
                'Interest' => 'ByMerchant',
                'Capture' => true,
                'SoftDescriptor' => $this->softDescriptor(),
                'CreditCard' => $creditCard,
            ],
        ];

        $url = CieloHttpClient::transactionalBase($credentials).'/1/sales';
        $response = CieloHttpClient::send($credentials, 'POST', $url, $body);

        if (! $response->successful()) {
            $this->throwFromResponse($response, 'Pagamento com cartão recusado.');
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $payment = is_array($data['Payment'] ?? null) ? $data['Payment'] : [];
        $paymentId = trim((string) ($payment['PaymentId'] ?? ''));
        if ($paymentId === '') {
            throw new \RuntimeException('Cielo: resposta de cartão sem PaymentId.');
        }

        $cieloStatus = isset($payment['Status']) && is_numeric($payment['Status']) ? (int) $payment['Status'] : null;
        $mapped = $cieloStatus !== null ? $this->mapPaymentStatus($cieloStatus) : 'pending';
        if ($mapped === 'cancelled') {
            $message = trim((string) ($payment['ReturnMessage'] ?? ''));
            throw new \RuntimeException('Cielo: '.($message !== '' ? $message : 'Cartão recusado ou pagamento não autorizado.'));
        }

        return [
            'transaction_id' => $paymentId,
            'status' => $mapped,
            'raw' => $data,
            'metadata' => array_filter([
                'cielo_tid' => isset($payment['Tid']) ? (string) $payment['Tid'] : null,
                'cielo_authorization_code' => isset($payment['AuthorizationCode']) ? (string) $payment['AuthorizationCode'] : null,
                'cielo_merchant_order_id' => (string) ($data['MerchantOrderId'] ?? $this->merchantOrderId($externalId)),
                'cielo_payment_status' => $cieloStatus,
                'installments' => $installments,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Cielo: boleto não está habilitado nesta integração.');
    }

    /**
     * Cancelamento/estorno (cartão) ou devolução PIX via PUT /1/sales/{PaymentId}/void.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{success: bool, pending?: bool, message?: string, error_code?: string, raw?: array<string, mixed>}
     */
    public function refundTransaction(array $credentials, string $txId, float $amount, string $externalId): array
    {
        $paymentId = trim($txId);
        if ($paymentId === '') {
            return [
                'success' => false,
                'message' => 'Cielo: PaymentId ausente para estorno.',
                'error_code' => 'missing_payment_id',
            ];
        }

        $amountCents = $this->amountToCents($amount);
        $path = '/1/sales/'.rawurlencode($paymentId).'/void?amount='.$amountCents;
        $urls = [CieloHttpClient::transactionalBase($credentials).$path];
        if (CieloHttpClient::isSandbox($credentials)) {
            $urls[] = rtrim(CieloHttpClient::TRANSACTIONAL_PRODUCTION, '/').$path;
        }

        $payload = [];
        $response = null;
        try {
            foreach (array_unique($urls) as $url) {
                $response = CieloHttpClient::send($credentials, 'PUT', $url);
                if ($response->status() !== 404) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CieloDriver refundTransaction request failed', [
                'order_id' => $externalId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return [
                'success' => false,
                'message' => 'Cielo: falha de comunicação ao solicitar estorno.',
                'error_code' => 'communication_failure',
            ];
        }

        if ($response === null) {
            return [
                'success' => false,
                'message' => 'Cielo: falha de comunicação ao solicitar estorno.',
                'error_code' => 'communication_failure',
            ];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if (! $response->successful()) {
            Log::warning('CieloDriver refundTransaction rejected', [
                'order_id' => $externalId,
                'status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message' => 'Cielo: '.$this->messageFromPayload($payload, 'A adquirente recusou o estorno.'),
                'error_code' => 'http_'.$response->status(),
                'raw' => $payload,
            ];
        }

        $status = isset($payload['Status']) && is_numeric($payload['Status']) ? (int) $payload['Status'] : null;
        $reason = strtolower((string) ($payload['ReasonMessage'] ?? ''));
        $pending = $status === 2 || $reason === 'scheduled';

        return [
            'success' => true,
            'pending' => $pending,
            'message' => $pending
                ? 'Estorno enviado à Cielo; aguardando confirmação.'
                : (string) ($payload['ReturnMessage'] ?? $payload['ProviderReturnMessage'] ?? 'Estorno solicitado.'),
            'raw' => $payload,
        ];
    }

    /**
     * paid | pending | cancelled
     */
    public function mapPaymentStatus(int $status): string
    {
        return match ($status) {
            2 => 'paid',
            3, 10, 11, 13 => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * @param  array<string, mixed>  $consumer
     * @return array<string, mixed>
     */
    private function buildCustomer(array $consumer, bool $forPix = false): array
    {
        $name = trim((string) ($consumer['name'] ?? ''));
        if ($name === '') {
            $name = 'Cliente';
        }
        $name = $this->ascii($name);
        if ($forPix) {
            $name = trim((string) preg_replace('/[^a-zA-Z ]+/', ' ', $name));
            $name = trim((string) preg_replace('/\s+/', ' ', $name));
            if ($name === '') {
                $name = 'Cliente';
            }
        }
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }

        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?? '';
        if (strlen($document) < 11) {
            $document = $forPix ? '' : '00000000000';
        }
        $identityType = strlen($document) >= 14 ? 'CNPJ' : 'CPF';

        $customer = [
            'Name' => $name,
        ];
        if ($document !== '') {
            $customer['Identity'] = $document;
            $customer['IdentityType'] = $identityType;
        }

        $email = trim((string) ($consumer['email'] ?? ''));
        if ($email !== '' && str_contains($email, '@')) {
            $customer['Email'] = $email;
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{payment_token: string, brand: string, installments: int}
     */
    private function parseCardPayload(array $card): array
    {
        $installments = isset($card['installments']) ? max(1, min(12, (int) $card['installments'])) : 1;
        $tokenRaw = $card['payment_token'] ?? '';
        $paymentToken = '';
        $brand = trim((string) ($card['brand'] ?? ''));

        if (is_string($tokenRaw) && $tokenRaw !== '') {
            $decoded = json_decode($tokenRaw, true);
            if (is_array($decoded)) {
                $paymentToken = trim((string) ($decoded['payment_token'] ?? $decoded['PaymentToken'] ?? $decoded['token'] ?? ''));
                if (isset($decoded['installments'])) {
                    $installments = max(1, min(12, (int) $decoded['installments']));
                }
                if ($brand === '') {
                    $brand = trim((string) ($decoded['brand'] ?? $decoded['Brand'] ?? ''));
                }
            } else {
                $paymentToken = trim($tokenRaw);
            }
        }

        return [
            'payment_token' => $paymentToken,
            'brand' => $this->mapBrand($brand),
            'installments' => $installments,
        ];
    }

    private function mapBrand(string $brand): string
    {
        $b = strtolower(trim($brand));
        if ($b === '') {
            return '';
        }
        if (str_contains($b, 'master')) {
            return 'Master';
        }
        if (str_contains($b, 'amex') || str_contains($b, 'american')) {
            return 'Amex';
        }
        if (str_contains($b, 'elo')) {
            return 'Elo';
        }
        if (str_contains($b, 'diners')) {
            return 'Diners';
        }
        if (str_contains($b, 'discover')) {
            return 'Discover';
        }
        if (str_contains($b, 'jcb')) {
            return 'JCB';
        }
        if (str_contains($b, 'aura')) {
            return 'Aura';
        }
        if (str_contains($b, 'hiper')) {
            return 'Hipercard';
        }
        if (str_contains($b, 'visa')) {
            return 'Visa';
        }

        return ucfirst($brand);
    }

    private function merchantOrderId(string $externalId, int $maxLength = 25): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $externalId) ?? '';
        if ($clean === '') {
            $clean = 'ord';
        }
        $maxLength = max(1, min(50, $maxLength));

        return substr($clean, 0, $maxLength);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     */
    private function firstNonEmptyString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (! isset($source[$key]) || ! is_scalar($source[$key])) {
                continue;
            }
            $value = trim((string) $source[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeQrImage(string $qr): string
    {
        $qr = trim($qr);
        if ($qr === '') {
            return '';
        }
        if (str_starts_with($qr, 'data:image')) {
            $parts = explode(',', $qr, 2);

            return isset($parts[1]) ? trim($parts[1]) : $qr;
        }

        return $qr;
    }

    private function amountToCents(float $amount): int
    {
        return (int) max(1, (int) round($amount * 100));
    }

    private function softDescriptor(): string
    {
        $raw = preg_replace('/[^a-zA-Z0-9 ]/', '', (string) config('app.name', 'LOJA')) ?? 'LOJA';
        $raw = trim($raw);
        if ($raw === '') {
            $raw = 'LOJA';
        }

        return substr($raw, 0, 13);
    }

    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (! is_string($converted) || $converted === '') {
            return preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
        }

        return $converted;
    }

    /**
     * @param  \Illuminate\Http\Client\Response  $response
     */
    private function throwFromResponse($response, string $fallback): never
    {
        $json = $response->json();
        $message = $this->messageFromPayload(is_array($json) ? $json : [], $fallback);
        Log::warning('CieloDriver request failed', [
            'status' => $response->status(),
            'message' => $message,
        ]);

        throw new \RuntimeException('Cielo: '.$message);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private function messageFromPayload(array $payload, string $fallback): string
    {
        if (isset($payload[0]) && is_array($payload[0])) {
            $first = $payload[0];
            foreach (['Message', 'ReturnMessage', 'ProviderReturnMessage'] as $key) {
                if (! empty($first[$key]) && is_string($first[$key])) {
                    return trim($first[$key]);
                }
            }
        }
        foreach (['ReturnMessage', 'ProviderReturnMessage', 'Message', 'ReasonMessage'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return trim($payload[$key]);
            }
        }

        return $fallback;
    }
}
