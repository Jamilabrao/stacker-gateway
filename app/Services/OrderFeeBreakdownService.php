<?php

namespace App\Services;

use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Schema;

/**
 * Valores de taxa/líquido de um pedido: usa o que foi creditado na carteira (imutável após a venda).
 */
class OrderFeeBreakdownService
{
    /**
     * @return array{gross: float, fee: float, net: float, from_wallet: bool}
     */
    public static function forOrder(Order $order, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? (int) $order->tenant_id;
        $grossFallback = (float) $order->lineItemsTotalAmount();

        if ($tenantId < 1) {
            return [
                'gross' => $grossFallback,
                'fee' => 0.0,
                'net' => $grossFallback,
                'from_wallet' => false,
            ];
        }

        $stored = self::fromWalletTransactions((int) $order->id, $tenantId);
        if ($stored !== null) {
            return $stored;
        }

        return array_merge(
            self::estimateFromCurrentRules($order, $tenantId, $grossFallback),
            ['from_wallet' => false]
        );
    }

    /**
     * @return array{gross: float, fee: float, net: float, from_wallet: bool}|null
     */
    private static function fromWalletTransactions(int $orderId, int $tenantId): ?array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return null;
        }

        $txs = WalletTransaction::query()
            ->where('order_id', $orderId)
            ->where('tenant_id', $tenantId)
            ->whereIn('type', [
                WalletTransaction::TYPE_CREDIT_SALE,
                WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            ])
            ->get(['type', 'amount_gross', 'amount_fee', 'amount_net', 'meta']);

        if ($txs->isEmpty()) {
            return null;
        }

        $credits = $txs->where('type', WalletTransaction::TYPE_CREDIT_SALE);
        if ($credits->isNotEmpty()) {
            return [
                'gross' => round((float) $credits->sum('amount_gross'), 2),
                'fee' => round((float) $credits->sum('amount_fee'), 2),
                'net' => round((float) $credits->sum('amount_net'), 2),
                'from_wallet' => true,
            ];
        }

        $pending = $txs
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->filter(function (WalletTransaction $tx) {
                $meta = is_array($tx->meta) ? $tx->meta : [];

                return empty($meta['released_at']);
            });

        if ($pending->isEmpty()) {
            return null;
        }

        return [
            'gross' => round((float) $pending->sum('amount_gross'), 2),
            'fee' => round((float) $pending->sum('amount_fee'), 2),
            'net' => round((float) $pending->sum('amount_net'), 2),
            'from_wallet' => true,
        ];
    }

    /**
     * Estimativa com regras atuais — apenas pedidos ainda sem crédito na carteira.
     *
     * @return array{gross: float, fee: float, net: float}
     */
    private static function estimateFromCurrentRules(Order $order, int $tenantId, float $gross): array
    {
        $meta = $order->metadata ?? [];
        $source = is_array($meta) ? ($meta['source'] ?? null) : null;
        $source = is_string($source) && $source !== '' ? $source : null;

        $calc = EffectiveMerchantFees::calculateSaleFee(
            $tenantId,
            EffectiveMerchantFees::feeMethodForOrder($order),
            $gross,
            $source
        );

        return [
            'gross' => $gross,
            'fee' => $calc['fee'],
            'net' => $calc['net'],
        ];
    }
}
