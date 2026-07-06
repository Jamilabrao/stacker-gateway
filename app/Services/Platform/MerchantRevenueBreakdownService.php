<?php

namespace App\Services\Platform;

use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MerchantRevenueBreakdownService
{
    /**
     * @return array{
     *     checkout: array{gross: float, count: int, fees: float},
     *     api_pix: array{gross: float, count: int, fees: float},
     *     total: array{gross: float, count: int, fees: float}
     * }
     */
    public function forTenant(int $tenantId): array
    {
        if ($tenantId < 1 || ! Schema::hasTable('orders')) {
            return $this->emptyBreakdown();
        }

        $base = $this->baseValidOrdersQuery($tenantId);

        $hasApiColumn = Schema::hasColumn('orders', 'api_application_id');

        if ($hasApiColumn) {
            $row = (clone $base)
                ->selectRaw('
                    COALESCE(SUM(CASE WHEN api_application_id IS NULL THEN amount ELSE 0 END), 0) as checkout_gross,
                    COALESCE(SUM(CASE WHEN api_application_id IS NOT NULL THEN amount ELSE 0 END), 0) as api_pix_gross,
                    COALESCE(SUM(CASE WHEN api_application_id IS NULL THEN 1 ELSE 0 END), 0) as checkout_count,
                    COALESCE(SUM(CASE WHEN api_application_id IS NOT NULL THEN 1 ELSE 0 END), 0) as api_pix_count
                ')
                ->first();
        } else {
            $row = (clone $base)
                ->selectRaw('
                    COALESCE(SUM(amount), 0) as checkout_gross,
                    0 as api_pix_gross,
                    COUNT(*) as checkout_count,
                    0 as api_pix_count
                ')
                ->first();
        }

        $checkoutGross = round((float) ($row->checkout_gross ?? 0), 2);
        $apiPixGross = round((float) ($row->api_pix_gross ?? 0), 2);
        $checkoutCount = (int) ($row->checkout_count ?? 0);
        $apiPixCount = (int) ($row->api_pix_count ?? 0);

        $checkoutOrderIds = $hasApiColumn
            ? (clone $base)->whereNull('api_application_id')->pluck('id')
            : (clone $base)->pluck('id');
        $apiPixOrderIds = $hasApiColumn
            ? (clone $base)->whereNotNull('api_application_id')->pluck('id')
            : collect();

        $checkoutFees = round($this->sumMerchantFeesForOrderIds($checkoutOrderIds), 2);
        $apiPixFees = round($this->sumMerchantFeesForOrderIds($apiPixOrderIds), 2);

        return [
            'checkout' => [
                'gross' => $checkoutGross,
                'count' => $checkoutCount,
                'fees' => $checkoutFees,
            ],
            'api_pix' => [
                'gross' => $apiPixGross,
                'count' => $apiPixCount,
                'fees' => $apiPixFees,
            ],
            'total' => [
                'gross' => round($checkoutGross + $apiPixGross, 2),
                'count' => $checkoutCount + $apiPixCount,
                'fees' => round($checkoutFees + $apiPixFees, 2),
            ],
        ];
    }

    /**
     * @return Builder<Order>
     */
    public function baseValidOrdersQuery(int $tenantId): Builder
    {
        return Order::forTenant($tenantId)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('approved_manually', false)
                    ->orWhereNull('approved_manually');
            })
            ->whereNotNull('gateway')
            ->where('gateway', '!=', 'manual');
    }

    /**
     * @return array{
     *     checkout: array{gross: float, count: int, fees: float},
     *     api_pix: array{gross: float, count: int, fees: float},
     *     total: array{gross: float, count: int, fees: float}
     * }
     */
    private function emptyBreakdown(): array
    {
        $zero = ['gross' => 0.0, 'count' => 0, 'fees' => 0.0];

        return [
            'checkout' => $zero,
            'api_pix' => $zero,
            'total' => $zero,
        ];
    }

    /**
     * Soma taxas sem duplicar: após liberação existem credit_sale_pending e credit_sale com o mesmo fee.
     *
     * @param  Collection<int, int|string>  $orderIds
     */
    private function sumMerchantFeesForOrderIds(Collection $orderIds): float
    {
        if ($orderIds->isEmpty() || ! Schema::hasTable('wallet_transactions')) {
            return 0.0;
        }

        $txs = WalletTransaction::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING])
            ->get(['order_id', 'type', 'amount_fee'])
            ->groupBy('order_id');

        $total = 0.0;
        foreach ($orderIds as $oid) {
            $group = $txs->get($oid, collect());
            if ($group->isEmpty()) {
                continue;
            }
            $hasReleasedSale = $group->contains(
                fn (WalletTransaction $t) => $t->type === WalletTransaction::TYPE_CREDIT_SALE
            );
            if ($hasReleasedSale) {
                $total += (float) $group
                    ->where('type', WalletTransaction::TYPE_CREDIT_SALE)
                    ->sum('amount_fee');
            } else {
                $total += (float) $group
                    ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
                    ->sum('amount_fee');
            }
        }

        return $total;
    }
}
