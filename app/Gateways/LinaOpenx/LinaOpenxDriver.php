<?php

namespace App\Gateways\LinaOpenx;

use App\Gateways\Contracts\GatewayDriver;
use App\Services\LinaOpenx\LinaOpenxClient;

class LinaOpenxDriver implements GatewayDriver
{
    public function __construct(
        private readonly LinaOpenxClient $client = new LinaOpenxClient()
    ) {}

    public function testConnection(array $credentials): bool
    {
        return $this->client->testConnection($credentials);
    }

    /**
     * Lina é Open Finance (redirect), não gera QR PIX.
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        throw new \RuntimeException('Lina OpenX não suporta PIX por QR Code. Use o método Open Finance.');
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('Lina OpenX não suporta pagamento com cartão.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Lina OpenX não suporta boleto.');
    }

    /**
     * Cria pagamento Instant via portal white-label.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array<string, mixed>  $options  redirect_uri, description, metadata
     * @return array{transaction_id: string, redirect_url: string, gateway: string, raw?: array}
     */
    public function createOpenFinancePayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $redirectUri,
        array $options = []
    ): array {
        $result = $this->client->createWhiteLabelPayment(
            $credentials,
            $amount,
            $consumer,
            $externalId,
            $redirectUri,
            $options
        );

        return [
            'transaction_id' => $result['transaction_id'],
            'redirect_url' => $result['redirect_url'],
            'gateway' => 'linaopenx',
            'raw' => $result['raw'] ?? [],
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        if (trim($transactionId) === '') {
            return null;
        }

        try {
            $result = $this->client->getPaymentRequest($credentials, $transactionId);
            $status = $result['status'] ?? 'pending';

            return in_array($status, ['paid', 'pending', 'cancelled', 'rejected'], true)
                ? $status
                : 'pending';
        } catch (\Throwable) {
            return null;
        }
    }
}
