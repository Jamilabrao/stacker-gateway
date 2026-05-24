<?php

namespace App\Services;

use App\Models\TenantWallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminWalletAdjustmentService
{
    /**
     * Ajuste relativo no saldo disponível de um bucket (permite saldo negativo).
     */
    public function adjust(int $tenantId, string $bucket, float $delta, string $note, Request $request): TenantWallet
    {
        if ($tenantId <= 0) {
            throw ValidationException::withMessages(['tenant_id' => 'Tenant inválido.']);
        }

        if (! Schema::hasTable('tenant_wallets')) {
            throw ValidationException::withMessages(['wallet' => 'Carteira não disponível neste ambiente.']);
        }

        $bucket = in_array($bucket, ['pix', 'card', 'boleto'], true) ? $bucket : 'pix';
        $delta = round($delta, 2);
        if ($delta == 0.0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor diferente de zero.']);
        }

        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Informe o motivo do ajuste.']);
        }

        $col = 'available_'.$bucket;
        if (! Schema::hasColumn('tenant_wallets', $col)) {
            throw ValidationException::withMessages(['bucket' => 'Bucket de saldo inválido.']);
        }

        $adminUserId = $request->user()?->id;

        return DB::transaction(function () use ($tenantId, $bucket, $delta, $note, $col, $adminUserId, $request) {
            $wallet = TenantWallet::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                $wallet = TenantWallet::query()->create([
                    'tenant_id' => $tenantId,
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'currency' => 'BRL',
                    'available_pix' => 0,
                    'available_card' => 0,
                    'available_boleto' => 0,
                    'pending_pix' => 0,
                    'pending_card' => 0,
                    'pending_boleto' => 0,
                ]);
                $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            }

            $before = (float) ($wallet->{$col} ?? 0);
            $after = round($before + $delta, 2);
            $wallet->{$col} = $after;

            if (Schema::hasColumn('tenant_wallets', 'available_balance')) {
                $wallet->available_balance = round(
                    (float) ($wallet->available_pix ?? 0)
                    + (float) ($wallet->available_card ?? 0)
                    + (float) ($wallet->available_boleto ?? 0),
                    2
                );
            }

            $wallet->save();

            if (Schema::hasTable('wallet_transactions')) {
                WalletTransaction::query()->create([
                    'tenant_id' => $tenantId,
                    'order_id' => null,
                    'withdrawal_id' => null,
                    'bucket' => $bucket,
                    'type' => WalletTransaction::TYPE_ADMIN_ADJUSTMENT,
                    'amount_gross' => abs($delta),
                    'amount_fee' => 0,
                    'amount_net' => $delta,
                    'meta' => [
                        'note' => $note,
                        'admin_user_id' => $adminUserId,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'bucket' => $bucket,
                    ],
                ]);
            }

            PlatformAuditService::log('platform.wallet.admin_adjustment', [
                'tenant_id' => $tenantId,
                'bucket' => $bucket,
                'delta' => $delta,
                'balance_before' => $before,
                'balance_after' => $after,
                'note' => $note,
            ], $request);

            return $wallet->fresh();
        });
    }
}
