<?php

namespace App\Services\CajuPay;

/**
 * Status de saque CajuPay compartilhado entre webhook e reconciliação.
 */
final class CajuPayPayoutStatuses
{
    /** @var list<string> */
    private const PAID_EVENTS = [
        'payout.paid',
        'payout.completed',
        'withdrawal.paid',
        'withdrawal.completed',
        'transfer.paid',
        'transfer.completed',
    ];

    /** @var list<string> */
    private const PAID_STATUSES = [
        'paid',
        'completed',
        'success',
        'succeeded',
        'settled',
        'done',
        'liquidated',
    ];

    /** @var list<string> */
    private const FAILED_STATUSES = [
        'failed',
        'rejected',
        'cancelled',
        'canceled',
        'error',
        'denied',
    ];

    /** @var list<string> */
    private const FAILED_EVENTS = [
        'payout.failed',
        'payout.cancelled',
        'payout.canceled',
        'payout.rejected',
        'withdrawal.failed',
        'withdrawal.cancelled',
        'withdrawal.canceled',
        'transfer.failed',
        'transfer.cancelled',
        'transfer.canceled',
    ];

    public static function isPaidEvent(string $eventType): bool
    {
        $eventType = strtolower(trim($eventType));

        return $eventType !== '' && in_array($eventType, self::PAID_EVENTS, true);
    }

    public static function isPaidStatus(string $status): bool
    {
        $status = strtolower(trim($status));

        return $status !== '' && in_array($status, self::PAID_STATUSES, true);
    }

    public static function isFailedStatus(string $status): bool
    {
        $status = strtolower(trim($status));

        return $status !== '' && in_array($status, self::FAILED_STATUSES, true);
    }

    public static function isFailedEvent(string $eventType): bool
    {
        $eventType = strtolower(trim($eventType));

        return $eventType !== '' && in_array($eventType, self::FAILED_EVENTS, true);
    }

    public static function isFailedConfirmation(string $eventType, string $status): bool
    {
        return self::isFailedEvent($eventType) || self::isFailedStatus($status);
    }

    public static function isPaidConfirmation(string $eventType, string $status): bool
    {
        return self::isPaidEvent($eventType) || self::isPaidStatus($status);
    }

    /**
     * Eventos de cashout/payout (não confundir com checkout/pix.payment).
     */
    public static function isPayoutRelatedEvent(string $eventType): bool
    {
        $eventType = strtolower(trim($eventType));
        if ($eventType === '') {
            return false;
        }

        return str_starts_with($eventType, 'payout.')
            || str_starts_with($eventType, 'withdrawal.')
            || str_starts_with($eventType, 'transfer.')
            || self::isPaidEvent($eventType)
            || self::isFailedEvent($eventType);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function looksLikePayoutPayload(array $payload): bool
    {
        if (self::isPayoutRelatedEvent(self::eventTypeFromWebhookPayload($payload))) {
            return true;
        }

        $payoutId = self::externalIdFromWebhookPayload($payload);
        if ($payoutId === '') {
            return false;
        }

        // IDs de payout oficiais / chaves típicas sem type de pagamento.
        foreach ([
            data_get($payload, 'data.object.cajupay_payout_id'),
            data_get($payload, 'data.cajupay_payout_id'),
            data_get($payload, 'cajupay_payout_id'),
            $payload['payout_id'] ?? null,
            $payload['withdrawal_id'] ?? null,
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function eventTypeFromWebhookPayload(array $payload): string
    {
        return strtolower(trim((string) ($payload['type'] ?? $payload['event'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function statusFromWebhookPayload(array $payload): string
    {
        $candidates = [
            $payload['status'] ?? null,
            data_get($payload, 'data.status'),
            data_get($payload, 'data.object.status'),
            data_get($payload, 'object.status'),
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return strtolower(trim((string) $candidate));
            }
        }

        return '';
    }

    /**
     * ID do payout na CajuPay (campo oficial: cajupay_payout_id em data.object).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function externalIdFromWebhookPayload(array $payload): string
    {
        $candidates = [
            data_get($payload, 'data.object.cajupay_payout_id'),
            data_get($payload, 'data.cajupay_payout_id'),
            data_get($payload, 'cajupay_payout_id'),
            $payload['id'] ?? null,
            $payload['payout_id'] ?? null,
            $payload['withdrawal_id'] ?? null,
            data_get($payload, 'data.id'),
            data_get($payload, 'data.payout_id'),
            data_get($payload, 'data.object.id'),
            data_get($payload, 'data.object.payout_id'),
            data_get($payload, 'payout.id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @return 'paid'|'pending'|'failed'|null null = erro de consulta ou status desconhecido
     */
    public static function settlementStatusFromRaw(?string $rawStatus): ?string
    {
        if ($rawStatus === null || trim($rawStatus) === '') {
            return null;
        }

        $raw = strtolower(trim($rawStatus));
        if (self::isPaidStatus($raw)) {
            return 'paid';
        }
        if (self::isFailedStatus($raw)) {
            return 'failed';
        }

        $pendingHints = ['pending', 'processing', 'in_progress', 'queued', 'submitted', 'created'];
        if (in_array($raw, $pendingHints, true)) {
            return 'pending';
        }

        return null;
    }
}
