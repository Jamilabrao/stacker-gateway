<?php

namespace App\Gateways\Versell;

use App\Gateways\Contracts\GatewayDriver;
use App\Services\Versell\VersellHttpClient;
use App\Services\Versell\VersellProblemDetails;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VersellDriver implements GatewayDriver
{
    public function __construct(
        private readonly VersellHttpClient $client = new VersellHttpClient()
    ) {}

    /**
     * Conexão “ativa” para cobrança = Cash In OK.
     * Cash Out é exigido só para saque (PlatformPayoutGateway).
     *
     * @param  array<string, mixed>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        $diagnosis = $this->diagnoseConnection($credentials);

        return ($diagnosis['cash_in']['ok'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{
     *   ok: bool,
     *   cash_in: array{ok: bool, message: string},
     *   cash_out: array{ok: bool, message: string},
     *   message: string
     * }
     */
    public function diagnoseConnection(array $credentials): array
    {
        $cashIn = $this->diagnoseApi(VersellCredentials::API_CASH_IN, $credentials);
        $cashOut = $this->diagnoseApi(VersellCredentials::API_CASH_OUT, $credentials);
        // ok = aparece na prioridade de PIX / Pix Automático
        $ok = $cashIn['ok'];

        $parts = [
            'Cash In: '.($cashIn['ok'] ? 'OK' : 'ERRO — '.$cashIn['message']),
            'Cash Out: '.($cashOut['ok'] ? 'OK' : 'ERRO — '.$cashOut['message']),
        ];

        return [
            'ok' => $ok,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'message' => implode(' | ', $parts),
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, message: string}
     */
    private function diagnoseApi(string $api, array $credentials): array
    {
        $label = $api === VersellCredentials::API_CASH_OUT ? 'Cash Out' : 'Cash In';
        $block = VersellCredentials::apiBlock($credentials, $api);

        $files = VersellCredentials::assertMtlsFiles($block, $label);
        if (! $files['ok']) {
            return ['ok' => false, 'message' => $files['error'] ?? 'Certificados ausentes.'];
        }

        if (trim((string) ($block['client_id'] ?? '')) === '' || trim((string) ($block['client_secret'] ?? '')) === '') {
            return ['ok' => false, 'message' => "{$label}: informe Client ID e Client Secret."];
        }

        try {
            if ($api === VersellCredentials::API_CASH_OUT) {
                $this->client->getCashOutAccessToken($credentials, true);
            } else {
                $this->client->getCashInAccessToken($credentials, true);
            }

            return ['ok' => true, 'message' => 'OAuth mTLS OK'];
        } catch (\Throwable $e) {
            Log::warning('VersellDriver diagnoseConnection failed', [
                'gateway' => 'versell',
                'api' => $api,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Cria cobrança PIX imediata (PUT /cob/{txid}).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array<string, mixed>  $options
     * @return array{transaction_id: string, qrcode?: string, copy_paste?: string, raw?: array}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $block = VersellCredentials::apiBlock($credentials, VersellCredentials::API_CASH_IN);
        $pixKey = trim((string) ($block['pix_key'] ?? ''));
        if ($pixKey === '') {
            throw new \RuntimeException('Versell: chave PIX (Cash In) não configurada.');
        }

        $files = VersellCredentials::assertMtlsFiles($block, 'Cash In');
        if (! $files['ok']) {
            throw new \RuntimeException($files['error'] ?? 'Versell: certificados Cash In inválidos.');
        }

        $txid = $this->makeTxid($externalId);
        $body = $this->buildCobBody($amount, $consumer, $pixKey, $externalId);

        try {
            $response = $this->client->request(
                VersellCredentials::API_CASH_IN,
                $credentials,
                'PUT',
                '/cob/'.$txid,
                $body
            );
        } catch (\Throwable $e) {
            Log::warning('VersellDriver createPixPayment request failed', [
                'gateway' => 'versell',
                'order_id' => $externalId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
            throw new \RuntimeException('Versell: não foi possível gerar o PIX. Tente novamente.');
        }

        if (! $response->successful()) {
            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            Log::warning('VersellDriver createPixPayment rejected', [
                'gateway' => 'versell',
                'order_id' => $externalId,
                'status' => $problem['status'],
                'error' => $problem['message'],
            ]);
            throw new \RuntimeException('Versell: '.$problem['message']);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $returnedTxid = trim((string) ($payload['txid'] ?? $txid));
        if ($returnedTxid === '') {
            throw new \RuntimeException('Versell: resposta sem txid da cobrança.');
        }

        $copyPaste = $payload['pixCopiaECola'] ?? $payload['pix_copia_e_cola'] ?? null;
        $copyPaste = is_string($copyPaste) && $copyPaste !== '' ? $copyPaste : null;

        if ($copyPaste === null) {
            Log::warning('VersellDriver createPixPayment missing pixCopiaECola', [
                'gateway' => 'versell',
                'order_id' => $externalId,
                'txid' => $returnedTxid,
            ]);
            throw new \RuntimeException('Versell: cobrança criada sem código PIX (pixCopiaECola).');
        }

        return [
            'transaction_id' => $returnedTxid,
            'copy_paste' => $copyPaste,
            'qrcode' => null,
            'raw' => $payload,
        ];
    }

    /**
     * Consulta cobrança GET /cob/{txid}.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $payload = $this->fetchCob($transactionId, $credentials);
        if ($payload === null) {
            return null;
        }

        $status = strtoupper(trim((string) ($payload['status'] ?? '')));

        return match ($status) {
            'CONCLUIDA' => 'paid',
            'ATIVA' => 'pending',
            'REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP' => 'cancelled',
            default => $this->inferStatusFromPixArray($payload) ?? 'pending',
        };
    }

    /**
     * Estorno PIX (Cash In): PUT /pix/{e2eid}/devolucao/{id}
     *
     * @param  array<string, mixed>  $credentials
     * @param  string  $txId  endToEndId do Pix recebido
     * @return array{success: bool, pending?: bool, message?: string, error_code?: string, raw?: array<string, mixed>, refund_id?: string}
     */
    public function refundTransaction(array $credentials, string $txId, float $amount, string $externalId): array
    {
        $endToEndId = trim($txId);
        if ($endToEndId === '') {
            return [
                'success' => false,
                'message' => 'Versell: endToEndId ausente para reembolso PIX.',
                'error_code' => 'missing_end_to_end_id',
            ];
        }

        $refundId = $this->makeRefundId($externalId);
        $body = [
            'valor' => number_format(round(max(0, $amount), 2), 2, '.', ''),
        ];

        try {
            $response = $this->client->request(
                VersellCredentials::API_CASH_IN,
                $credentials,
                'PUT',
                '/pix/'.rawurlencode($endToEndId).'/devolucao/'.rawurlencode($refundId),
                $body
            );
        } catch (\Throwable $e) {
            Log::warning('VersellDriver refundTransaction request failed', [
                'gateway' => 'versell',
                'order_id' => $externalId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return [
                'success' => false,
                'message' => 'Versell: falha de comunicação ao solicitar devolução PIX.',
                'error_code' => 'communication_failure',
            ];
        }

        if (! $response->successful()) {
            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            Log::warning('VersellDriver refundTransaction rejected', [
                'gateway' => 'versell',
                'order_id' => $externalId,
                'status' => $problem['status'],
                'error' => $problem['message'],
            ]);

            return [
                'success' => false,
                'message' => 'Versell: '.$problem['message'],
                'error_code' => $problem['status'] !== null ? 'http_'.$problem['status'] : 'refund_rejected',
                'raw' => is_array($response->json()) ? $response->json() : [],
            ];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        return $this->mapDevolucaoResponse($payload, $refundId);
    }

    /**
     * Obtém endToEndId do metadata do pedido ou via GET /cob/{txid}.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $metadata
     */
    public function resolveEndToEndId(array $credentials, ?string $txid, ?array $metadata): ?string
    {
        $fromMeta = trim((string) ($metadata['versell_end_to_end_id'] ?? ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $txid = trim((string) $txid);
        if ($txid === '') {
            return null;
        }

        $cob = $this->fetchCob($txid, $credentials);
        if ($cob === null) {
            return null;
        }

        return $this->extractEndToEndIdFromCob($cob);
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('Versell não suporta cartão neste fluxo.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Versell não emite boleto no checkout.');
    }

    /**
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @return array<string, mixed>
     */
    private function buildCobBody(float $amount, array $consumer, string $pixKey, string $externalId): array
    {
        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?: '';
        $name = trim((string) ($consumer['name'] ?? 'Cliente'));
        if ($name === '') {
            $name = 'Cliente';
        }

        $devedor = ['nome' => $name];
        if (strlen($document) === 14) {
            $devedor['cnpj'] = $document;
        } else {
            $devedor['cpf'] = strlen($document) >= 11 ? substr($document, 0, 11) : '00000000000';
        }

        return [
            'calendario' => [
                'expiracao' => 3600,
            ],
            'devedor' => $devedor,
            'valor' => [
                'original' => number_format(round($amount, 2), 2, '.', ''),
            ],
            'chave' => $pixKey,
            'solicitacaoPagador' => 'Pedido #'.$externalId,
            'infoAdicionais' => [
                ['nome' => 'order_id', 'valor' => (string) $externalId],
            ],
        ];
    }

    private function makeTxid(string $externalId): string
    {
        $base = 'vs'.preg_replace('/[^a-zA-Z0-9]/', '', $externalId);
        $base = is_string($base) ? substr($base, 0, 18) : 'vs';
        $txid = $base.Str::lower(Str::random(16));
        $txid = preg_replace('/[^a-zA-Z0-9]/', '', $txid) ?: ('vs'.bin2hex(random_bytes(12)));
        if (strlen($txid) < 26) {
            $txid .= bin2hex(random_bytes(8));
        }

        return substr($txid, 0, 35);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function inferStatusFromPixArray(array $payload): ?string
    {
        $pix = $payload['pix'] ?? null;
        if (is_array($pix) && $pix !== []) {
            return 'paid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    private function fetchCob(string $transactionId, array $credentials): ?array
    {
        $txid = trim($transactionId);
        if ($txid === '') {
            return null;
        }

        try {
            $response = $this->client->request(
                VersellCredentials::API_CASH_IN,
                $credentials,
                'GET',
                '/cob/'.$txid
            );
        } catch (\Throwable $e) {
            Log::warning('VersellDriver fetchCob failed', [
                'gateway' => 'versell',
                'txid' => $txid,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }

        if ($response->status() === 404 || ! $response->successful()) {
            if ($response->status() !== 404) {
                Log::warning('VersellDriver fetchCob http error', [
                    'gateway' => 'versell',
                    'txid' => $txid,
                    'status' => $response->status(),
                ]);
            }

            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, pending?: bool, message?: string, error_code?: string, raw?: array<string, mixed>, refund_id?: string}
     */
    private function mapDevolucaoResponse(array $payload, string $refundId): array
    {
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));

        if (in_array($status, ['DEVOLVIDO', 'REFUNDED', 'COMPLETED', 'CONCLUIDA'], true)) {
            return [
                'success' => true,
                'pending' => false,
                'message' => 'Devolução PIX confirmada na Versell.',
                'refund_id' => $refundId,
                'raw' => $payload,
            ];
        }

        if ($status === '' || in_array($status, ['EM_PROCESSAMENTO', 'PROCESSING', 'PENDING'], true)) {
            return [
                'success' => true,
                'pending' => true,
                'message' => 'Devolução PIX enviada; aguardando liquidação.',
                'refund_id' => $refundId,
                'raw' => $payload,
            ];
        }

        if (in_array($status, ['NAO_REALIZADO', 'FAILED', 'REJECTED', 'CANCELADO', 'CANCELLED'], true)) {
            return [
                'success' => false,
                'message' => 'Versell: devolução não realizada ('.$status.').',
                'error_code' => strtolower($status),
                'refund_id' => $refundId,
                'raw' => $payload,
            ];
        }

        return [
            'success' => true,
            'pending' => true,
            'message' => 'Devolução PIX aceita pela Versell.',
            'refund_id' => $refundId,
            'raw' => $payload,
        ];
    }

    private function makeRefundId(string $externalId): string
    {
        $id = 'o'.preg_replace('/[^a-zA-Z0-9]/', '', $externalId).'rfnd';
        $id = is_string($id) ? $id : 'orfnd';
        if (strlen($id) < 8) {
            $id .= bin2hex(random_bytes(4));
        }

        return substr($id, 0, 35);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractEndToEndIdFromCob(array $payload): ?string
    {
        $pix = $payload['pix'] ?? null;
        if (! is_array($pix) || $pix === []) {
            return null;
        }

        $first = $pix[0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        $e2e = trim((string) ($first['endToEndId'] ?? $first['end_to_end_id'] ?? ''));

        return $e2e !== '' ? $e2e : null;
    }
}
