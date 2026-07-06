<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;

class OrderManualRefund
{
    public static function canManualRefund(Order $order): bool
    {
        return in_array($order->status, ['completed', 'disputed'], true);
    }

    /**
     * @param  array{status?: string, note?: string|null}  $gatewayRefund
     * @return array{
     *     initiated_by: string,
     *     initiated_by_user_id: int,
     *     initiated_by_name: string,
     *     reason: string|null,
     *     refunded_at: string,
     *     gateway_refund: array{status: string|null, note: string|null}
     * }
     */
    public static function buildMeta(User $actor, string $initiatedBy, ?string $reason, array $gatewayRefund): array
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return [
            'initiated_by' => $initiatedBy,
            'initiated_by_user_id' => (int) $actor->id,
            'initiated_by_name' => (string) $actor->name,
            'reason' => $reason !== '' ? $reason : null,
            'refunded_at' => now()->toIso8601String(),
            'gateway_refund' => [
                'status' => isset($gatewayRefund['status']) ? (string) $gatewayRefund['status'] : null,
                'note' => isset($gatewayRefund['note']) && $gatewayRefund['note'] !== null
                    ? (string) $gatewayRefund['note']
                    : null,
            ],
        ];
    }

    /**
     * @return array{
     *     initiated_by: string,
     *     initiated_by_label: string,
     *     initiated_by_user_id: int,
     *     initiated_by_name: string,
     *     reason: string|null,
     *     refunded_at: string|null,
     *     gateway_refund: array{status: string|null, note: string|null}
     * }|null
     */
    public static function snapshot(Order $order): ?array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $block = $meta['manual_refund'] ?? null;
        if (! is_array($block)) {
            return null;
        }

        $initiatedBy = (string) ($block['initiated_by'] ?? '');

        return [
            'initiated_by' => $initiatedBy,
            'initiated_by_label' => match ($initiatedBy) {
                'platform' => 'Plataforma',
                'seller' => 'Infoprodutor',
                default => $initiatedBy !== '' ? $initiatedBy : '—',
            },
            'initiated_by_user_id' => (int) ($block['initiated_by_user_id'] ?? 0),
            'initiated_by_name' => (string) ($block['initiated_by_name'] ?? ''),
            'reason' => isset($block['reason']) && $block['reason'] !== ''
                ? (string) $block['reason']
                : null,
            'refunded_at' => isset($block['refunded_at']) && $block['refunded_at'] !== ''
                ? (string) $block['refunded_at']
                : null,
            'gateway_refund' => [
                'status' => isset($block['gateway_refund']['status'])
                    ? (string) $block['gateway_refund']['status']
                    : null,
                'note' => isset($block['gateway_refund']['note']) && $block['gateway_refund']['note'] !== ''
                    ? (string) $block['gateway_refund']['note']
                    : null,
            ],
        ];
    }
}
