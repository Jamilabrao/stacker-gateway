<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TenantWallet;
use App\Models\WalletTransaction;
use App\Support\MoneyDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Impede o infoprodutor de reembolsar se a carteira não cobre o valor líquido a estornar.
 */
class SellerRefundBalanceGuard
{
    public static function assertSufficient(Order $order): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            return;
        }
        if (! Schema::hasColumn('tenant_wallets', 'available_pix')) {
            return;
        }

        DB::transaction(function () use ($order) {
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

            foreach ($lines->groupBy(fn ($line) => (int) $line->tenant_id.'|'.(string) $line->bucket) as $tenantLines) {
                $required = MoneyDecimal::toFloat($tenantLines->sum(fn ($line) => (float) $line->amount_net));
                if ($required <= 0) {
                    continue;
                }

                $tenantId = (int) $tenantLines->first()->tenant_id;
                [$availCol, $pendCol] = self::bucketColumns((string) $tenantLines->first()->bucket);

                $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
                $current = $wallet === null
                    ? 0.0
                    : MoneyDecimal::toFloat(
                        (float) ($wallet->{$availCol} ?? 0) + (float) ($wallet->{$pendCol} ?? 0)
                    );

                if (bccomp(MoneyDecimal::normalize($current), MoneyDecimal::normalize($required), 2) < 0) {
                    throw new InvalidArgumentException(self::denialMessage($current, $required));
                }
            }
        });
    }

    public static function denialMessage(float $current, float $required): string
    {
        return 'Seu saldo atual ('.self::formatBrl($current).') é menor que o necessário para seguir com o reembolso ('.self::formatBrl($required).').';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function bucketColumns(string $bucket): array
    {
        $availCol = 'available_'.$bucket;
        $pendCol = 'pending_'.$bucket;
        if (! in_array($availCol, ['available_pix', 'available_card', 'available_boleto'], true)) {
            return ['available_pix', 'pending_pix'];
        }

        return [$availCol, $pendCol];
    }

    private static function formatBrl(float $amount): string
    {
        return 'R$ '.number_format(MoneyDecimal::toFloat($amount), 2, ',', '.');
    }
}
