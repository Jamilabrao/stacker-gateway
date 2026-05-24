<?php

namespace App\Http\Controllers\Concerns;

use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWalletAdminBlockService;
use Illuminate\Support\Facades\Schema;

trait BuildsMerchantWalletProps
{
    /**
     * @return array<string, mixed>|null
     */
    protected function walletPayloadForTenant(int $tenantId): ?array
    {
        if ($tenantId <= 0 || ! Schema::hasTable('tenant_wallets')) {
            return null;
        }

        $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->first();
        if ($wallet === null) {
            return [
                'available_pix' => 0.0,
                'available_card' => 0.0,
                'available_boleto' => 0.0,
                'pending_pix' => 0.0,
                'pending_card' => 0.0,
                'pending_boleto' => 0.0,
                'available_total' => 0.0,
                'pending_total' => 0.0,
                'med_total' => MerchantWalletAdminBlockService::totalMedHoldAmountForTenant($tenantId),
                'effective_withdrawal_pix' => 0.0,
                'wallet_admin' => null,
            ];
        }

        MerchantWalletAdminBlockService::expireAdminBlockIfNeeded($wallet);
        $wallet->refresh();

        $pp = (float) ($wallet->pending_pix ?? 0);
        $pc = (float) ($wallet->pending_card ?? 0);
        $pb = (float) ($wallet->pending_boleto ?? 0);

        $walletAdmin = null;
        if (Schema::hasColumn('tenant_wallets', 'admin_withdrawal_blocked')) {
            $walletAdmin = [
                'admin_withdrawal_blocked' => (bool) $wallet->admin_withdrawal_blocked,
                'admin_blocked_amount' => $wallet->admin_blocked_amount !== null ? (float) $wallet->admin_blocked_amount : null,
                'admin_block_until' => $wallet->admin_block_until?->toIso8601String(),
                'admin_block_note' => $wallet->admin_block_note,
            ];
        }

        return [
            'available_pix' => (float) ($wallet->available_pix ?? 0),
            'available_card' => (float) ($wallet->available_card ?? 0),
            'available_boleto' => (float) ($wallet->available_boleto ?? 0),
            'pending_pix' => $pp,
            'pending_card' => $pc,
            'pending_boleto' => $pb,
            'available_total' => round(
                (float) ($wallet->available_pix ?? 0)
                + (float) ($wallet->available_card ?? 0)
                + (float) ($wallet->available_boleto ?? 0),
                2
            ),
            'pending_total' => round($pp + $pc + $pb, 2),
            'med_total' => MerchantWalletAdminBlockService::totalMedHoldAmountForTenant($tenantId),
            'effective_withdrawal_pix' => MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, 'pix'),
            'wallet_admin' => $walletAdmin,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function withdrawalsPayloadForTenant(int $tenantId, int $limit = 50): array
    {
        if ($tenantId <= 0 || ! Schema::hasTable('withdrawals')) {
            return [];
        }

        return Withdrawal::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Withdrawal $w) => [
                'id' => $w->id,
                'amount' => (float) $w->amount,
                'fee_amount' => (float) ($w->fee_amount ?? 0),
                'net_amount' => (float) ($w->net_amount ?? 0),
                'bucket' => $w->bucket ?? 'pix',
                'status' => (string) $w->status,
                'notes' => $w->notes,
                'created_at' => $w->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function walletTransactionsPayloadForTenant(int $tenantId, int $limit = 80): array
    {
        if ($tenantId <= 0 || ! Schema::hasTable('wallet_transactions')) {
            return [];
        }

        $labels = WalletTransaction::typeLabels();

        return WalletTransaction::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (WalletTransaction $t) use ($labels) {
                $meta = is_array($t->meta) ? $t->meta : [];

                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'type_label' => $labels[$t->type] ?? $t->type,
                    'bucket' => $t->bucket,
                    'amount_net' => (float) $t->amount_net,
                    'order_id' => $t->order_id,
                    'withdrawal_id' => $t->withdrawal_id,
                    'note' => $meta['note'] ?? null,
                    'created_at' => $t->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    protected function tenantIdForUser(User $user): int
    {
        return (int) ($user->tenant_id ?? $user->id);
    }
}
