<?php

namespace Plugins\OnlyUp;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Log;

class OnlyUpDriver implements GatewayDriver
{
    /**
     * {@inheritdoc}
     */
    public function testConnection(array $credentials): bool
    {
        try {
            OnlyUpHttp::getCashInAccessToken($credentials);
            OnlyUpHttp::getCashOutAccessToken($credentials);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        $pixKey = trim((string) ($credentials['pix_key'] ?? ''));
        if ($pixKey === '') {
            throw new \RuntimeException('OnlyUp: configure a chave PIX (DICT).');
        }

        $token = OnlyUpHttp::getCashInAccessToken($credentials);
        $txid = $this->generateTxid();
        $doc = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?? '';
        $name = $this->sanitizeName((string) ($consumer['name'] ?? ''));

        $devedor = [];
        if (strlen($doc) === 14) {
            $devedor['cnpj'] = $doc;
            $devedor['nome'] = $name !== '' ? $name : 'Pagador';
        } elseif (strlen($doc) === 11) {
            $devedor['cpf'] = $doc;
            $devedor['nome'] = $name !== '' ? $name : 'Pagador';
        } else {
            throw new \RuntimeException('OnlyUp: CPF ou CNPJ do pagador inválido para gerar cobrança.');
        }

        $body = [
            'calendario' => [
                'expiracao' => 3600,
            ],
            'devedor' => $devedor,
            'valor' => [
                'original' => number_format($amount, 2, '.', ''),
                'modalidadeAlteracao' => 0,
            ],
            'chave' => $pixKey,
            'infoAdicionais' => [
                ['nome' => 'Pedido', 'valor' => (string) $externalId],
            ],
        ];

        $client = OnlyUpHttp::cashInClient($credentials)->withToken($token);
        $response = $client->put('/cob/'.$txid, $body);

        if (! $response->successful()) {
            Log::warning('OnlyUp create cob failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('OnlyUp: '.($response->json('detail') ?? 'Erro ao gerar cobrança PIX.'));
        }

        $data = $response->json();
        $copy = (string) ($data['pixCopiaECola'] ?? '');
        if ($copy === '') {
            throw new \RuntimeException('OnlyUp: resposta sem código PIX (pixCopiaECola).');
        }

        return [
            'transaction_id' => $txid,
            'qrcode' => null,
            'copy_paste' => $copy,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $txid = trim($transactionId);
        if ($txid === '') {
            return null;
        }
        try {
            $token = OnlyUpHttp::getCashInAccessToken($credentials);
            $client = OnlyUpHttp::cashInClient($credentials)->withToken($token);
            $response = $client->get('/cob/'.$txid);
            if ($response->status() === 404) {
                return null;
            }
            if (! $response->successful()) {
                Log::info('OnlyUp get cob non-success', ['status' => $response->status(), 'txid' => $txid]);

                return null;
            }
            $raw = (string) ($response->json('status') ?? '');
            $s = strtoupper(str_replace([' ', '-'], ['_', '_'], $raw));

            return match (true) {
                in_array($s, ['CONCLUIDA', 'COMPLETED'], true) => 'paid',
                in_array($s, ['ATIVA', 'ACTIVE'], true) => 'pending',
                str_contains($s, 'REMOVIDA') || in_array($s, ['REMOVED_BY_RECIPIENT', 'REMOVED_BY_PSP', 'CANCELED', 'CANCELLED'], true) => 'cancelled',
                default => 'pending',
            };
        } catch (\Throwable $e) {
            Log::warning('OnlyUp getTransactionStatus error', ['txid' => $txid, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Consulta transferência cash-out por idempotency key (saques).
     *
     * @param  array<string, mixed>  $credentials
     * @return 'paid'|'pending'|'cancelled'|null
     */
    public function getPayoutTransferStatus(string $idempotencyKey, array $credentials): ?string
    {
        $key = trim($idempotencyKey);
        if ($key === '') {
            return null;
        }
        try {
            $token = OnlyUpHttp::getCashOutAccessToken($credentials);
            $client = OnlyUpHttp::cashOutClient($credentials)->withToken($token);
            $response = $client->get('/api/v2/pix/payments/idempotencyKey/'.$key);
            if ($response->status() === 404) {
                return null;
            }
            if (! $response->successful()) {
                return null;
            }
            $status = strtoupper((string) data_get($response->json(), 'data.status', ''));

            return match (true) {
                $status === 'LIQUIDATED' => 'paid',
                $status === 'CANCELED' || $status === 'CANCELLED' => 'cancelled',
                $status === 'PROCESSING' => 'pending',
                default => 'pending',
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('OnlyUp não suporta pagamento com cartão.');
    }

    /**
     * {@inheritdoc}
     */
    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('OnlyUp não suporta boleto.');
    }

    private function generateTxid(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $len = 32;
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }

    private function sanitizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if (strlen($name) > 80) {
            $name = substr($name, 0, 80);
        }

        return $name;
    }
}
