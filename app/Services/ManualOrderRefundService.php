<?php

namespace App\Services;

use App\Jobs\PollCajuPayPixRefundJob;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Support\OrderManualRefund;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ManualOrderRefundService
{
    public function __construct(
        protected OrderRefundGatewayBridge $gatewayBridge,
    ) {}

    /**
     * @return array{success: bool, message: string, gateway_status: string}
     */
    public function refund(Order $order, User $actor, string $initiatedBy, ?string $reason = null): array
    {
        if (! OrderManualRefund::canManualRefund($order)) {
            throw new InvalidArgumentException('Só é possível reembolsar pedidos pagos ou em MED.');
        }

        if (! in_array($initiatedBy, ['seller', 'platform'], true)) {
            throw new InvalidArgumentException('Origem do reembolso inválida.');
        }

        $gw = $this->gatewayBridge->tryRefund($order);

        if ($gw['status'] === 'blocked_med') {
            return [
                'success' => false,
                'message' => $gw['note'] ?? 'Reembolso bloqueado por disputa MED aberta.',
                'gateway_status' => 'blocked_med',
            ];
        }

        if ($gw['status'] === 'failed') {
            return [
                'success' => false,
                'message' => $gw['note'] ?? 'Falha ao solicitar reembolso no gateway.',
                'gateway_status' => 'failed',
            ];
        }

        if ($gw['status'] === 'gateway_pending') {
            PollCajuPayPixRefundJob::dispatch($order->id)->delay(now()->addSeconds(5));
            $this->recordApprovedRefundRequest(
                $order,
                $actor,
                $reason,
                $gw,
                pendingGateway: true
            );

            return [
                'success' => true,
                'message' => $gw['note'] ?? 'Reembolso PIX enviado à CajuPay. A carteira será ajustada quando a devolução for confirmada.',
                'gateway_status' => 'gateway_pending',
            ];
        }

        $manualRefundMeta = OrderManualRefund::buildMeta($actor, $initiatedBy, $reason, $gw);
        $debitReason = $initiatedBy === 'platform' ? 'platform_manual_refund' : 'seller_manual_refund';

        PlatformOrderAdminService::refundPaidOrDisputed($order, $manualRefundMeta, $debitReason);
        $this->recordApprovedRefundRequest($order->fresh(), $actor, $reason, $gw);

        return [
            'success' => true,
            'message' => 'Pedido #'.$order->id.' reembolsado.',
            'gateway_status' => (string) ($gw['status'] ?? 'gateway_ok'),
        ];
    }

    /**
     * Garante que o reembolso manual apareça em Vendas → Reembolsos (aba Aprovados).
     *
     * @param  array{status?: string, note?: string|null}  $gw
     */
    private function recordApprovedRefundRequest(
        Order $order,
        User $actor,
        ?string $reason,
        array $gw,
        bool $pendingGateway = false,
    ): void {
        if (! Schema::hasTable('refund_requests') || ! $order->user_id) {
            return;
        }

        $customerReason = trim((string) ($reason ?? ''));
        if ($customerReason === '') {
            $customerReason = $pendingGateway
                ? 'Reembolso iniciado pelo vendedor/plataforma (aguardando confirmação no gateway).'
                : 'Reembolso iniciado pelo vendedor/plataforma.';
        }

        $existing = RefundRequest::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        $payload = [
            'status' => RefundRequest::STATUS_APPROVED,
            'resolved_by_user_id' => $actor->id,
            'resolved_at' => now(),
            'gateway_refund_status' => $gw['status'] ?? null,
            'gateway_refund_note' => $gw['note'] ?? null,
        ];

        if ($existing !== null) {
            if ($existing->status === RefundRequest::STATUS_PENDING
                || $existing->status === RefundRequest::STATUS_APPROVED) {
                $existing->update($payload);
            }

            return;
        }

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'tenant_id' => (int) $order->tenant_id,
            'customer_reason' => $customerReason,
            ...$payload,
        ]);
    }
}
