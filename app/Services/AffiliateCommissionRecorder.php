<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\SaleOrigin;
use Illuminate\Support\Facades\Schema;

class AffiliateCommissionRecorder
{
    public static function recordForOrder(Order $order): ?AffiliateCommission
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            return null;
        }

        $order = $order->fresh(['orderItems', 'product', 'user']);

        if ($order->status !== 'completed' || ! $order->isAffiliateSale()) {
            return null;
        }

        $existing = AffiliateCommission::query()->where('order_id', $order->id)->first();
        if ($existing !== null) {
            return self::syncStatusFromWallet($existing);
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $enrollmentId = (int) ($order->affiliate_enrollment_id ?? $meta['affiliate_enrollment_id'] ?? 0);
        $affiliateUserId = (int) ($order->affiliate_user_id ?? $meta['affiliate_user_id'] ?? 0);

        if ($enrollmentId < 1 || $affiliateUserId < 1) {
            return null;
        }

        $enrollment = ProductAffiliateEnrollment::query()
            ->with('affiliate')
            ->find($enrollmentId);

        if ($enrollment === null) {
            return null;
        }

        $product = $order->product ?? Product::query()->find($order->product_id);
        if ($product === null) {
            return null;
        }

        $grossTotal = (float) $order->lineItemsTotalAmount();
        $slice = ProductCoproducer::affiliateGrossSliceFromOrder($order, $grossTotal);
        if ($slice === null || (float) ($slice['gross'] ?? 0) <= 0) {
            return null;
        }

        $commissionGross = (float) $slice['gross'];
        $commissionPercent = (float) $product->affiliate_commission_percent;

        $affiliateUser = $enrollment->affiliate ?? User::query()->find($affiliateUserId);
        $affiliateTenantId = (int) ($affiliateUser?->tenant_id ?? $affiliateUserId);

        $walletTx = self::findAffiliateWalletTransaction($order->id, $affiliateTenantId);
        $commissionFee = $walletTx ? (float) $walletTx->amount_fee : 0.0;
        $commissionNet = $walletTx ? (float) $walletTx->amount_net : 0.0;

        if ($walletTx === null) {
            $source = is_string($meta['source'] ?? null) ? $meta['source'] : null;
            $feeMethod = EffectiveMerchantFees::feeMethodForOrder($order);
            $feeCalc = EffectiveMerchantFees::calculateSaleFee($affiliateTenantId, $feeMethod, $commissionGross, $source);
            $commissionFee = (float) $feeCalc['fee'];
            $commissionNet = (float) $feeCalc['net'];
        }

        $affiliateRef = is_string($meta['affiliate_ref'] ?? null) ? $meta['affiliate_ref'] : $enrollment->public_ref;
        $affiliateLink = self::buildAffiliateLink($product, $affiliateRef);

        $status = self::resolveStatus($order, $walletTx);

        $commission = AffiliateCommission::query()->create([
            'order_id' => $order->id,
            'affiliate_user_id' => $affiliateUserId,
            'affiliate_enrollment_id' => $enrollmentId,
            'producer_tenant_id' => (int) $order->tenant_id,
            'producer_user_id' => (int) $order->tenant_id,
            'product_id' => (string) $order->product_id,
            'sale_origin' => SaleOrigin::resolveForOrder($order),
            'commission_percent' => $commissionPercent,
            'sale_gross' => $grossTotal,
            'commission_gross' => $commissionGross,
            'commission_fee' => $commissionFee,
            'commission_net' => $commissionNet,
            'status' => $status,
            'wallet_transaction_id' => $walletTx?->id,
            'affiliate_ref' => $affiliateRef,
            'affiliate_link' => $affiliateLink,
            'metadata' => self::buildSnapshot($order, $affiliateUser),
        ]);

        return $commission;
    }

    public static function markRefundedForOrder(Order $order): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            return;
        }

        AffiliateCommission::query()
            ->where('order_id', $order->id)
            ->update(['status' => AffiliateCommission::STATUS_REFUNDED]);
    }

    public static function markCancelledForOrder(Order $order): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            return;
        }

        AffiliateCommission::query()
            ->where('order_id', $order->id)
            ->where('status', AffiliateCommission::STATUS_PENDING)
            ->update(['status' => AffiliateCommission::STATUS_CANCELLED]);
    }

    public static function syncStatusFromWallet(AffiliateCommission $commission): AffiliateCommission
    {
        $order = $commission->order ?? Order::query()->find($commission->order_id);
        if ($order === null) {
            return $commission;
        }

        if ($order->status === 'refunded') {
            $commission->status = AffiliateCommission::STATUS_REFUNDED;
            $commission->save();

            return $commission;
        }

        if (in_array($order->status, ['cancelled', 'failed', 'expired'], true)) {
            $commission->status = AffiliateCommission::STATUS_CANCELLED;
            $commission->save();

            return $commission;
        }

        $walletTx = $commission->wallet_transaction_id
            ? WalletTransaction::query()->find($commission->wallet_transaction_id)
            : self::findAffiliateWalletTransaction($order->id, self::affiliateTenantIdFromCommission($commission));

        if ($walletTx !== null) {
            $commission->wallet_transaction_id = $walletTx->id;
            $commission->commission_fee = (float) $walletTx->amount_fee;
            $commission->commission_net = (float) $walletTx->amount_net;
            $commission->status = self::resolveStatus($order, $walletTx);
            $commission->save();
        }

        return $commission;
    }

    private static function affiliateTenantIdFromCommission(AffiliateCommission $commission): int
    {
        $affiliate = $commission->affiliate ?? User::query()->find($commission->affiliate_user_id);

        return (int) ($affiliate?->tenant_id ?? $commission->affiliate_user_id);
    }

    private static function findAffiliateWalletTransaction(int $orderId, int $affiliateTenantId): ?WalletTransaction
    {
        return WalletTransaction::query()
            ->where('order_id', $orderId)
            ->where('tenant_id', $affiliateTenantId)
            ->whereIn('type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING])
            ->orderByRaw("CASE type WHEN '".WalletTransaction::TYPE_CREDIT_SALE."' THEN 0 ELSE 1 END")
            ->get()
            ->first(function (WalletTransaction $tx) {
                $meta = is_array($tx->meta) ? $tx->meta : [];

                return ($meta['coproduction_role'] ?? null) === 'affiliate';
            });
    }

    private static function resolveStatus(Order $order, ?WalletTransaction $walletTx): string
    {
        if ($order->status === 'refunded') {
            return AffiliateCommission::STATUS_REFUNDED;
        }

        if (in_array($order->status, ['cancelled', 'failed', 'expired'], true)) {
            return AffiliateCommission::STATUS_CANCELLED;
        }

        if ($walletTx === null) {
            return AffiliateCommission::STATUS_PENDING;
        }

        if ($walletTx->type === WalletTransaction::TYPE_CREDIT_SALE) {
            return AffiliateCommission::STATUS_APPROVED;
        }

        $meta = is_array($walletTx->meta) ? $walletTx->meta : [];
        if (! empty($meta['released_at'])) {
            return AffiliateCommission::STATUS_APPROVED;
        }

        return AffiliateCommission::STATUS_PENDING;
    }

    private static function buildAffiliateLink(?Product $product, ?string $ref): ?string
    {
        if ($product === null || $ref === null || trim($ref) === '' || ! $product->checkout_slug) {
            return null;
        }

        $base = url('/c/'.$product->checkout_slug);

        return $base.(str_contains($base, '?') ? '&' : '?').'ref='.urlencode(trim($ref));
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildSnapshot(Order $order, ?User $affiliateUser): array
    {
        return [
            'customer_name' => $order->user?->name,
            'customer_email' => $order->email ?? $order->user?->email,
            'payment_method' => $order->payment_method,
            'order_status' => $order->status,
            'affiliate_name' => $affiliateUser?->name,
            'affiliate_email' => $affiliateUser?->email,
            'producer_name' => $order->tenantOwner?->name,
        ];
    }
}
