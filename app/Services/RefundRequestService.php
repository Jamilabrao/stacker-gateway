<?php

namespace App\Services;

use App\Mail\RefundDecisionCustomerMail;
use App\Mail\RefundRequestSellerMail;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CajuPay\CajuPayPixRefundConfirmationService;
use App\Services\PlatformOrderAdminService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RefundRequestService
{
    public function __construct(
        protected OrderRefundGatewayBridge $gatewayBridge
    ) {}

    public function createFromCustomer(Order $order, User $customer, string $reason): RefundRequest
    {
        return RefundRequest::create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'tenant_id' => (int) $order->tenant_id,
            'status' => RefundRequest::STATUS_PENDING,
            'customer_reason' => $reason,
        ]);
    }

    public function notifySeller(RefundRequest $request): void
    {
        $owner = User::query()->find($request->tenant_id);
        if (! $owner || ! $owner->email) {
            Log::warning('RefundRequestService: titular do tenant sem e-mail.', ['tenant_id' => $request->tenant_id]);

            return;
        }

        $url = url('/vendas/reembolsos');
        Mail::to($owner->email)->send(new RefundRequestSellerMail($request->fresh(['order.product']), $url));
    }

    public function notifyPlatformAdmins(RefundRequest $request): void
    {
        app(PlatformEmailNotifications::class)->refundRequested($request->fresh(['order.product']));
    }

    public function approve(User $seller, RefundRequest $request): void
    {
        if ($request->status !== RefundRequest::STATUS_PENDING) {
            throw new \InvalidArgumentException('Solicitação não está pendente.');
        }
        $order = $request->order;
        if (! $order || (int) $order->tenant_id !== (int) $seller->tenant_id) {
            throw new \InvalidArgumentException('Pedido inválido.');
        }

        $gw = $this->gatewayBridge->tryRefund($order);
        $request->update([
            'gateway_refund_status' => $gw['status'],
            'gateway_refund_note' => $gw['note'],
        ]);

        if ($gw['status'] === 'blocked_med') {
            throw new \InvalidArgumentException($gw['note'] ?? 'Reembolso bloqueado por disputa MED aberta.');
        }

        if ($gw['status'] === 'failed') {
            throw new \InvalidArgumentException($gw['note'] ?? 'Falha ao solicitar reembolso no gateway.');
        }

        if (CajuPayPixRefundConfirmationService::isCajuPixOrder($order)
            && in_array($gw['status'], ['gateway_ok', 'gateway_pending'], true)) {
            app(CajuPayPixRefundConfirmationService::class)
                ->lockWalletAndAwait($order, null, 'seller_manual_refund');
            $request->update([
                'status' => RefundRequest::STATUS_APPROVED,
                'resolved_by_user_id' => $seller->id,
                'resolved_at' => now(),
            ]);
            $customer = $request->user;
            if ($customer && $customer->email) {
                Mail::to($customer->email)->send(new RefundDecisionCustomerMail($request->fresh(['order.product']), true, $gw['note'] ?? null));
            }

            $this->logSellerDecision($seller, $request, SellerActivityLogService::REFUND_REQUEST_APPROVED, [
                'gateway_status' => $gw['status'] ?? null,
            ]);

            return;
        }

        try {
            PlatformOrderAdminService::refundPaidOrDisputed($order->fresh());
        } catch (\Throwable $e) {
            Log::error('RefundRequestService: falha ao estornar carteira.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $request->update([
            'status' => RefundRequest::STATUS_APPROVED,
            'resolved_by_user_id' => $seller->id,
            'resolved_at' => now(),
        ]);

        $customer = $request->user;
        if ($customer && $customer->email) {
            Mail::to($customer->email)->send(new RefundDecisionCustomerMail($request->fresh(['order.product']), true, null));
        }

        $this->logSellerDecision($seller, $request, SellerActivityLogService::REFUND_REQUEST_APPROVED);
    }

    public function reject(User $seller, RefundRequest $request, ?string $reason): void
    {
        if ($request->status !== RefundRequest::STATUS_PENDING) {
            throw new \InvalidArgumentException('Solicitação não está pendente.');
        }
        $order = $request->order;
        if (! $order || (int) $order->tenant_id !== (int) $seller->tenant_id) {
            throw new \InvalidArgumentException('Pedido inválido.');
        }

        $request->update([
            'status' => RefundRequest::STATUS_REJECTED,
            'seller_rejection_reason' => $reason,
            'resolved_by_user_id' => $seller->id,
            'resolved_at' => now(),
        ]);

        $customer = $request->user;
        if ($customer && $customer->email) {
            Mail::to($customer->email)->send(new RefundDecisionCustomerMail($request->fresh(['order.product']), false, $reason));
        }

        $this->logSellerDecision($seller, $request, SellerActivityLogService::REFUND_REQUEST_REJECTED, [
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logSellerDecision(User $seller, RefundRequest $request, string $action, array $extra = []): void
    {
        SellerActivityLogService::record(
            actor: $seller,
            action: $action,
            targetType: RefundRequest::class,
            targetId: $request->id,
            metadata: array_filter([
                'refund_request_id' => $request->id,
                'order_id' => $request->order_id,
                ...$extra,
            ], fn ($v) => $v !== null && $v !== ''),
            tenantId: (int) $request->tenant_id,
        );
    }
}
