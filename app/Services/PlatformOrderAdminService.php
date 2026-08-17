<?php

namespace App\Services;

use App\Events\OrderCancelled;
use App\Events\OrderRefunded;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\TenantWallet;
use App\Models\WalletTransaction;
use App\Services\AffiliateCommissionRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PlatformOrderAdminService
{
    public static function cancelPending(Order $order): void
    {
        if ($order->status !== 'pending') {
            throw new InvalidArgumentException('Só é possível cancelar pedidos pendentes.');
        }

        $order->update(['status' => 'cancelled']);
        AffiliateCommissionRecorder::markCancelledForOrder($order->fresh());
        event(new OrderCancelled($order->fresh()));
    }

    /**
     * Marca contestação (MED). Pedido pendente ou pago.
     * Se já estava pago e creditado na carteira, o valor líquido passa de disponível para pendente (bloqueio temporário).
     */
    public static function markDisputed(Order $order): void
    {
        if ($order->status === 'disputed') {
            throw new InvalidArgumentException('Este pedido já está em MED.');
        }

        if (! in_array($order->status, ['pending', 'completed'], true)) {
            throw new InvalidArgumentException('Só é possível marcar como MED pedidos pendentes ou pagos.');
        }

        DB::transaction(function () use ($order) {
            if ($order->status === 'completed') {
                self::moveAvailableToPendingForMed($order);
            }
            $order->update(['status' => 'disputed']);
        });
    }

    /**
     * Reembolso manual: pedido pago ou em MED; estorno na carteira (primeiro do pendente, depois do disponível).
     *
     * @param  array<string, mixed>|null  $manualRefundMeta
     */
    public static function refundPaidOrDisputed(
        Order $order,
        ?array $manualRefundMeta = null,
        string $debitReason = 'platform_manual_refund',
    ): void {
        if (! in_array($order->status, ['completed', 'disputed'], true)) {
            throw new InvalidArgumentException('Só é possível reembolsar pedidos pagos ou em MED.');
        }

        DB::transaction(function () use ($order, $manualRefundMeta, $debitReason) {
            self::applyLocalRefundEffects($order, $manualRefundMeta, $debitReason, 'refunded', fireRefundedEvent: true);
        });
    }

    /**
     * Caju aceitou o pedido (submitted/pending_balance): debita a carteira agora e deixa o pedido
     * em "reembolso em andamento" até o webhook/polling confirmar.
     *
     * @param  array<string, mixed>|null  $manualRefundMeta
     */
    public static function beginPendingGatewayRefund(
        Order $order,
        ?array $manualRefundMeta = null,
        string $debitReason = 'seller_manual_refund',
    ): void {
        if (! in_array($order->status, ['completed', 'disputed'], true)) {
            throw new InvalidArgumentException('Só é possível reembolsar pedidos pagos ou em MED.');
        }

        DB::transaction(function () use ($order, $manualRefundMeta, $debitReason) {
            self::applyLocalRefundEffects($order, $manualRefundMeta, $debitReason, 'refund_pending', fireRefundedEvent: false);
        });
    }

    /**
     * @param  array<string, mixed>|null  $manualRefundMeta
     */
    private static function applyLocalRefundEffects(
        Order $order,
        ?array $manualRefundMeta,
        string $debitReason,
        string $status,
        bool $fireRefundedEvent,
    ): void {
        self::reverseSaleCreditIfExists($order, $debitReason);
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if ($manualRefundMeta !== null) {
            $meta['manual_refund'] = $manualRefundMeta;
        }
        $order->update([
            'status' => $status,
            'metadata' => $meta,
        ]);
        AffiliateCommissionRecorder::markRefundedForOrder($order->fresh());
        ReferralCommissionRecorder::reverseForOrder($order->fresh());
        $order->fresh()?->revokePurchasedProductAccessFromBuyer();
        if ($fireRefundedEvent) {
            event(new OrderRefunded($order->fresh()));
        }
    }

    /**
     * @deprecated Use {@see refundPaidOrDisputed}
     */
    public static function refundCompleted(Order $order): void
    {
        self::refundPaidOrDisputed($order);
    }

    /**
     * Bloqueia o crédito da venda: move de available_* para pending_* (MED), por tenant (co-produção).
     */
    private static function moveAvailableToPendingForMed(Order $order): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            return;
        }
        if (! Schema::hasColumn('tenant_wallets', 'available_pix') || ! Schema::hasColumn('tenant_wallets', 'pending_pix')) {
            return;
        }

        $availableCredits = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE)
            ->get();

        $pendingCredits = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->get()
            ->filter(function ($tx) {
                $m = is_array($tx->meta) ? $tx->meta : [];

                return empty($m['released_at']);
            });

        if ($availableCredits->isEmpty() && $pendingCredits->isEmpty()) {
            return;
        }

        $tenantIds = $availableCredits->pluck('tenant_id')
            ->merge($pendingCredits->pluck('tenant_id'))
            ->unique()
            ->filter(fn ($id) => (int) $id > 0);

        foreach ($tenantIds as $tenantId) {
            $tid = (int) $tenantId;
            $credit = $availableCredits->firstWhere('tenant_id', $tid);
            $pending = $pendingCredits->where('tenant_id', $tid)->values();
            self::moveAvailableToPendingForMedTenant($order, $tid, $credit, $pending);
        }
    }

    private static function moveAvailableToPendingForMedTenant(Order $order, int $tenantId, ?WalletTransaction $credit, Collection $pendingCredits): void
    {
        if ($tenantId < 1) {
            return;
        }

        if (WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('tenant_id', $tenantId)
            ->where('type', WalletTransaction::TYPE_MED_HOLD)
            ->exists()) {
            return;
        }

        if ($credit === null && $pendingCredits->isEmpty()) {
            return;
        }

        $bucket = $credit !== null
            ? (string) $credit->bucket
            : (string) $pendingCredits->first()->bucket;

        $net = $credit !== null
            ? (float) $credit->amount_net
            : (float) $pendingCredits->sum('amount_net');

        $availCol = 'available_'.$bucket;
        $pendCol = 'pending_'.$bucket;
        if (! in_array($availCol, ['available_pix', 'available_card', 'available_boleto'], true)) {
            $availCol = 'available_pix';
            $pendCol = 'pending_pix';
        }

        $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
        if ($wallet === null) {
            return;
        }

        $heldNet = 0.0;
        if ($credit !== null) {
            $available = (float) ($wallet->{$availCol} ?? 0);
            $move = min($net, max(0, $available));
            if ($move > 0) {
                $wallet->{$availCol} = round($available - $move, 2);
                $wallet->{$pendCol} = round((float) ($wallet->{$pendCol} ?? 0) + $move, 2);
                $heldNet = $move;
            }
        }

        self::recalcWalletAggregates($wallet);
        $wallet->save();

        $grossRef = $credit !== null ? (float) $credit->amount_gross : (float) $pendingCredits->sum('amount_gross');
        $feeRef = $credit !== null ? (float) $credit->amount_fee : (float) $pendingCredits->sum('amount_fee');

        WalletTransaction::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'withdrawal_id' => null,
            'bucket' => $bucket,
            'type' => WalletTransaction::TYPE_MED_HOLD,
            'amount_gross' => $grossRef,
            'amount_fee' => $feeRef,
            'amount_net' => $net,
            'meta' => [
                'credit_sale_wallet_transaction_id' => $credit?->id,
                'credit_sale_pending_ids' => $pendingCredits->pluck('id')->values()->all(),
                'held_net' => round($heldNet, 2),
                'hold_mode' => $credit !== null ? 'available_to_pending' : 'pending_only',
                'reason' => 'med_dispute_hold',
            ],
        ]);
    }

    private static function reverseSaleCreditIfExists(Order $order, string $debitReason = 'platform_manual_refund'): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            return;
        }
        if (! Schema::hasColumn('tenant_wallets', 'available_pix')) {
            return;
        }

        if (WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_DEBIT_REFUND)
            ->exists()) {
            return;
        }

        $lines = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING])
            ->orderBy('id')
            ->get()
            ->filter(function ($line) {
                if ($line->type === WalletTransaction::TYPE_CREDIT_SALE) {
                    return true;
                }
                $m = is_array($line->meta) ? $line->meta : [];

                return empty($m['released_at']);
            });

        if ($lines->isEmpty()) {
            return;
        }

        foreach ($lines->groupBy('tenant_id') as $tenantId => $tenantLines) {
            self::reverseSaleCreditsForTenant($order, (int) $tenantId, $tenantLines, $debitReason);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, WalletTransaction>  $lines
     */
    private static function reverseSaleCreditsForTenant(
        Order $order,
        int $tenantId,
        Collection $lines,
        string $debitReason = 'platform_manual_refund',
    ): void
    {
        if ($tenantId < 1 || $lines->isEmpty()) {
            return;
        }

        $bucket = (string) $lines->first()->bucket;
        $availCol = 'available_'.$bucket;
        $pendCol = 'pending_'.$bucket;
        if (! in_array($availCol, ['available_pix', 'available_card', 'available_boleto'], true)) {
            $availCol = 'available_pix';
            $pendCol = 'pending_pix';
        }

        $totalNet = 0.0;
        $totalGross = 0.0;
        $totalFee = 0.0;
        $refIds = [];

        DB::transaction(function () use ($lines, $tenantId, $availCol, $pendCol, &$totalNet, &$totalGross, &$totalFee, &$refIds) {
            $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($wallet === null) {
                return;
            }

            foreach ($lines as $line) {
                $n = (float) $line->amount_net;
                if ($n <= 0) {
                    continue;
                }
                $refIds[] = $line->id;
                $totalNet += $n;
                $totalGross += (float) $line->amount_gross;
                $totalFee += (float) $line->amount_fee;

                if ($line->type === WalletTransaction::TYPE_CREDIT_SALE_PENDING) {
                    self::takeFromWalletBucket($wallet, $pendCol, $availCol, $n);
                } elseif ($line->type === WalletTransaction::TYPE_CREDIT_SALE) {
                    self::takeFromWalletBucket($wallet, $availCol, $pendCol, $n);
                }
            }

            self::recalcWalletAggregates($wallet);
            $wallet->save();
        });

        if ($totalNet <= 0 || $refIds === []) {
            return;
        }

        WalletTransaction::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'withdrawal_id' => null,
            'bucket' => $bucket,
            'type' => WalletTransaction::TYPE_DEBIT_REFUND,
            'amount_gross' => round($totalGross, 2),
            'amount_fee' => round($totalFee, 2),
            'amount_net' => round($totalNet, 2),
            'meta' => [
                'reverses_wallet_transaction_ids' => $refIds,
                'reason' => $debitReason,
            ],
        ]);
    }

    /**
     * Libera hold MED e restaura pedido para pago (disputa ganha/cancelada).
     */
    public static function releaseMedHoldAndComplete(Order $order): void
    {
        DB::transaction(function () use ($order) {
            self::releaseMedHold($order);
            if ($order->fresh()->status === 'disputed') {
                $order->update(['status' => 'completed']);
            }
        });
    }

    /**
     * Reverte bloqueio MED: pending_* de volta para available_*.
     */
    public static function releaseMedHold(Order $order): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            return;
        }

        $holds = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_MED_HOLD)
            ->get()
            ->filter(function ($tx) {
                $m = is_array($tx->meta) ? $tx->meta : [];

                return empty($m['released_at']);
            });

        if ($holds->isEmpty()) {
            return;
        }

        foreach ($holds->groupBy('tenant_id') as $tenantId => $tenantHolds) {
            self::releaseMedHoldForTenant($order, (int) $tenantId, $tenantHolds);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, WalletTransaction>  $holds
     */
    private static function releaseMedHoldForTenant(Order $order, int $tenantId, Collection $holds): void
    {
        if ($tenantId < 1 || $holds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($order, $tenantId, $holds) {
            $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($wallet === null) {
                return;
            }

            foreach ($holds as $hold) {
                $meta = is_array($hold->meta) ? $hold->meta : [];
                if (! empty($meta['released_at'])) {
                    continue;
                }

                $net = (float) $hold->amount_net;
                if ($net <= 0) {
                    $hold->update(['meta' => array_merge($meta, ['released_at' => now()->toIso8601String()])]);

                    continue;
                }

                $bucket = (string) $hold->bucket;
                $availCol = 'available_'.$bucket;
                $pendCol = 'pending_'.$bucket;
                if (! in_array($availCol, ['available_pix', 'available_card', 'available_boleto'], true)) {
                    $availCol = 'available_pix';
                    $pendCol = 'pending_pix';
                }

                $holdMode = (string) ($meta['hold_mode'] ?? 'available_to_pending');
                $heldNet = isset($meta['held_net']) ? (float) $meta['held_net'] : null;
                $releaseNet = $holdMode === 'available_to_pending' && $heldNet !== null
                    ? min($net, max(0, $heldNet))
                    : $net;

                $pending = (float) ($wallet->{$pendCol} ?? 0);
                $move = min($releaseNet, max(0, $pending));
                if ($move > 0) {
                    $wallet->{$pendCol} = round($pending - $move, 2);
                    $wallet->{$availCol} = round((float) ($wallet->{$availCol} ?? 0) + $move, 2);
                }

                $hold->update(['meta' => array_merge($meta, [
                    'released_at' => now()->toIso8601String(),
                    'released_net' => round($move, 2),
                    'reason' => 'med_dispute_released',
                ])]);
            }

            self::recalcWalletAggregates($wallet);
            $wallet->save();
        });
    }

    /**
     * Reembolso confirmado via webhook/API sem nova chamada ao gateway.
     */
    public static function applyGatewayRefund(Order $order): void
    {
        if ($order->status === 'refunded') {
            return;
        }

        if ($order->status === 'refund_pending') {
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'refunded']);
                self::ensureApprovedRefundRequestForGateway($order->fresh());
            });
            event(new OrderRefunded($order->fresh()));

            return;
        }

        if (! in_array($order->status, ['completed', 'disputed'], true)) {
            return;
        }

        self::refundPaidOrDisputed($order);
        self::ensureApprovedRefundRequestForGateway($order->fresh());
    }

    /**
     * Histórico em Vendas → Reembolsos quando o estorno veio do gateway/webhook.
     */
    private static function ensureApprovedRefundRequestForGateway(?Order $order): void
    {
        if ($order === null || ! Schema::hasTable('refund_requests') || ! $order->user_id) {
            return;
        }

        $exists = RefundRequest::query()->where('order_id', $order->id)->exists();
        if ($exists) {
            RefundRequest::query()
                ->where('order_id', $order->id)
                ->where('status', RefundRequest::STATUS_PENDING)
                ->update([
                    'status' => RefundRequest::STATUS_APPROVED,
                    'resolved_at' => now(),
                    'gateway_refund_status' => 'gateway_ok',
                ]);

            return;
        }

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'tenant_id' => (int) $order->tenant_id,
            'status' => RefundRequest::STATUS_APPROVED,
            'customer_reason' => 'Reembolso confirmado pelo gateway de pagamento.',
            'gateway_refund_status' => 'gateway_ok',
            'resolved_at' => now(),
        ]);
    }

    private static function takeFromWalletBucket(
        TenantWallet $wallet,
        string $primaryCol,
        string $secondaryCol,
        float $amount,
    ): void {
        $remaining = $amount;
        $primary = (float) ($wallet->{$primaryCol} ?? 0);
        $takePrimary = min($remaining, max(0, $primary));
        $wallet->{$primaryCol} = round($primary - $takePrimary, 2);
        $remaining = round($remaining - $takePrimary, 2);
        if ($remaining > 0.0001) {
            $secondary = (float) ($wallet->{$secondaryCol} ?? 0);
            $takeSecondary = min($remaining, max(0, $secondary));
            $wallet->{$secondaryCol} = round($secondary - $takeSecondary, 2);
        }
    }

    private static function recalcWalletAggregates(TenantWallet $wallet): void
    {
        if (Schema::hasColumn('tenant_wallets', 'available_balance')) {
            $wallet->available_balance = round(
                (float) ($wallet->available_pix ?? 0)
                + (float) ($wallet->available_card ?? 0)
                + (float) ($wallet->available_boleto ?? 0),
                2
            );
        }
        if (Schema::hasColumn('tenant_wallets', 'pending_balance')) {
            $wallet->pending_balance = round(
                (float) ($wallet->pending_pix ?? 0)
                + (float) ($wallet->pending_card ?? 0)
                + (float) ($wallet->pending_boleto ?? 0),
                2
            );
        }
    }
}
