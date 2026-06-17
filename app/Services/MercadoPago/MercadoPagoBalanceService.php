<?php

namespace App\Services\MercadoPago;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MercadoPagoBalanceService
{
    private const BASE_URL = 'https://api.mercadopago.com';

    /**
     * @return array{
     *     user_id: int,
     *     email: string|null,
     *     nickname: string|null,
     *     total_amount: float,
     *     available_balance: float,
     *     unavailable_balance: float,
     *     currency_id: string,
     *     is_sandbox: bool,
     *     fetched_at: string
     * }
     */
    public function fetchBalance(string $accessToken): array
    {
        $token = trim($accessToken);
        if ($token === '') {
            throw new RuntimeException('Mercado Pago: Access Token não configurado.');
        }

        $user = $this->fetchCurrentUser($token);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Mercado Pago: não foi possível identificar a conta (users/me).');
        }

        $balance = $this->fetchAccountBalance($token, $userId);

        return [
            'user_id' => $userId,
            'email' => isset($user['email']) ? (string) $user['email'] : null,
            'nickname' => isset($user['nickname']) ? (string) $user['nickname'] : null,
            'total_amount' => $this->toFloat($balance['total_amount'] ?? 0),
            'available_balance' => $this->toFloat($balance['available_balance'] ?? 0),
            'unavailable_balance' => $this->toFloat($balance['unavailable_balance'] ?? 0),
            'currency_id' => strtoupper((string) ($balance['currency_id'] ?? 'BRL')),
            'is_sandbox' => str_starts_with($token, 'TEST-'),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCurrentUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(20)
            ->get(self::BASE_URL.'/users/me');

        if (! $response->successful()) {
            $this->logFailure('users/me', $response->status(), $response->json());
            throw new RuntimeException($this->messageForStatus($response->status(), 'conta Mercado Pago'));
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Mercado Pago: resposta inválida ao consultar a conta.');
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAccountBalance(string $accessToken, int $userId): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(20)
            ->get(self::BASE_URL.'/users/'.$userId.'/mercadopago_account/balance');

        if (! $response->successful()) {
            $this->logFailure('mercadopago_account/balance', $response->status(), $response->json());
            throw new RuntimeException($this->messageForStatus($response->status(), 'saldo Mercado Pago'));
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Mercado Pago: resposta inválida ao consultar o saldo.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function logFailure(string $endpoint, int $status, mixed $body): void
    {
        $message = is_array($body) ? ($body['message'] ?? null) : null;
        Log::debug('MercadoPagoBalanceService request failed', [
            'endpoint' => $endpoint,
            'status' => $status,
            'message' => is_string($message) ? $message : null,
        ]);
    }

    private function messageForStatus(int $status, string $context): string
    {
        return match ($status) {
            401 => 'Mercado Pago: Access Token inválido ou expirado.',
            403 => 'Mercado Pago: token sem permissão para consultar '.$context.'.',
            404 => 'Mercado Pago: endpoint de saldo indisponível para esta conta. Use os relatórios Released money / Account money no painel MP.',
            default => 'Mercado Pago: falha ao consultar '.$context.' (HTTP '.$status.').',
        };
    }

    private function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }
        if (is_string($value) && is_numeric($value)) {
            return round((float) $value, 2);
        }

        return 0.0;
    }
}
