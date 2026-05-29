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

    public static function isPaidConfirmation(string $eventType, string $status): bool
    {
        return self::isPaidEvent($eventType) || self::isPaidStatus($status);
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
