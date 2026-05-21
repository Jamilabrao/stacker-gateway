<?php

namespace App\Support;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;

/**
 * Monta o bloco `customer` dos webhooks (integrações e API de pagamentos).
 * CPF e telefone só entram no array quando preenchidos.
 */
final class WebhookCustomerPayload
{
    /**
     * @return array<string, string>
     */
    public static function fromOrder(Order $order): array
    {
        $order->loadMissing(['user', 'checkoutSession']);

        $meta = is_array($order->metadata) ? $order->metadata : [];

        $name = trim((string) ($order->user?->name ?? ''));
        if ($name === '') {
            $name = trim((string) ($meta['customer_name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string) ($order->checkoutSession?->name ?? ''));
        }

        $email = trim((string) ($order->email ?? $order->user?->email ?? ''));

        $customer = array_filter([
            'name' => $name,
            'email' => $email,
        ], fn (string $v) => $v !== '');

        $cpf = self::normalizeCpf($order->cpf ?? ($meta['customer_cpf'] ?? null));
        if ($cpf !== null) {
            $customer['cpf'] = $cpf;
        }

        $phone = self::normalizePhone($order->phone ?? ($meta['customer_phone'] ?? null));
        if ($phone !== null) {
            $customer['phone'] = $phone;
        }

        return $customer;
    }

    /**
     * @return array<string, string>
     */
    public static function fromCheckoutSession(CheckoutSession $session): array
    {
        $customer = array_filter([
            'name' => trim((string) ($session->name ?? '')),
            'email' => trim((string) ($session->email ?? '')),
        ], fn (string $v) => $v !== '');

        $cpf = self::normalizeCpf($session->cpf ?? null);
        if ($cpf !== null) {
            $customer['cpf'] = $cpf;
        }

        $phone = self::normalizePhone($session->phone ?? null);
        if ($phone !== null) {
            $customer['phone'] = $phone;
        }

        return $customer;
    }

    /**
     * @return array<string, string>
     */
    public static function fromUser(?User $user, ?string $fallbackEmail = null): array
    {
        if ($user === null && ($fallbackEmail === null || trim($fallbackEmail) === '')) {
            return [];
        }

        $customer = array_filter([
            'name' => trim((string) ($user?->name ?? '')),
            'email' => trim((string) ($fallbackEmail ?? $user?->email ?? '')),
        ], fn (string $v) => $v !== '');

        $cpf = self::normalizeCpf($user?->document);
        if ($cpf !== null) {
            $customer['cpf'] = $cpf;
        }

        return $customer;
    }

    public static function normalizeCpf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }

    public static function normalizePhone(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
