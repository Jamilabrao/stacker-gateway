<?php

namespace App\Services;

use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWalletAdminBlockService;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Payout\PlatformPayoutGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MerchantWithdrawalService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Reserva o saque para aprovação/envio PIX (evita duplo clique / requests paralelos).
     */
    public static function beginPayoutApproval(int $withdrawalId): ?Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId) {
            $withdrawal = Withdrawal::query()->whereKey($withdrawalId)->lockForUpdate()->first();
            if ($withdrawal === null || $withdrawal->status !== self::STATUS_PENDING) {
                return null;
            }

            $withdrawal->status = self::STATUS_PROCESSING;
            $withdrawal->save();

            return $withdrawal->fresh();
        });
    }

    /**
     * Reverte para pendente quando o envio ao gateway falha antes de concluir o saque.
     */
    public static function releasePayoutApproval(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal) {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== self::STATUS_PROCESSING) {
                return;
            }

            $locked->status = self::STATUS_PENDING;
            $locked->save();
        });
    }
    /**
     * Valor solicitado é o bruto debitado da carteira; a taxa incide sobre esse valor.
     *
     * @throws ValidationException
     */
    public static function requestWithdrawal(User $user, float $amount, string $bucket, ?string $notes = null): Withdrawal
    {
        if (! $user->isInfoprodutor()) {
            abort(403, 'Apenas o titular da conta pode solicitar saques.');
        }

        $tenantId = (int) ($user->tenant_id ?? $user->id);
        if ($tenantId < 1) {
            throw ValidationException::withMessages(['amount' => 'Conta inválida.']);
        }

        $bucket = in_array($bucket, ['pix', 'card', 'boleto'], true) ? $bucket : 'pix';
        $col = 'available_'.$bucket;
        if (! Schema::hasColumn('tenant_wallets', $col)) {
            throw ValidationException::withMessages(['amount' => 'Carteiras indisponíveis.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }

        $feeCalc = EffectiveMerchantFees::calculateWithdrawalFee($tenantId, $amount);
        if ($feeCalc['net'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'O valor líquido após taxas deve ser maior que zero.']);
        }

        if (PlatformPayoutGateway::isEnabled()) {
            $required = GatewayPayoutEconomics::forActiveGateway()['required_min_net'];
            if ($feeCalc['net'] + 0.0001 < $required) {
                $minGross = EffectiveMerchantFees::minimumWithdrawalGrossForTargetNet($tenantId, $required);
                $msg = $minGross !== null
                    ? 'O valor mínimo do saque é R$ '
                        .number_format($minGross, 2, ',', '.').' (valor total a solicitar).'
                    : 'O valor solicitado é inferior ao mínimo permitido. Aumente o valor ou contate o suporte.';

                throw ValidationException::withMessages(['amount' => $msg]);
            }
        }

        return DB::transaction(function () use ($user, $tenantId, $amount, $feeCalc, $bucket, $col, $notes) {
            $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($wallet === null) {
                throw ValidationException::withMessages(['amount' => 'Carteira não encontrada.']);
            }

            $available = MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, $bucket);
            if ($available + 0.0001 < $amount) {
                $msg = 'Saldo disponível insuficiente nesta carteira.';
                if (Schema::hasColumn('tenant_wallets', 'admin_withdrawal_blocked') && filter_var($wallet->admin_withdrawal_blocked ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $msg = 'Saques bloqueados pela plataforma. Contate o suporte.';
                } elseif (Schema::hasColumn('tenant_wallets', 'admin_blocked_amount') && (float) ($wallet->admin_blocked_amount ?? 0) > 0) {
                    $msg = 'Saldo disponível insuficiente após reserva administrativa nesta carteira.';
                }
                throw ValidationException::withMessages(['amount' => $msg]);
            }

            // Debita do saldo bruto da carteira; `effectiveAvailableForWithdrawal` pode descontar bloqueios administrativos,
            // mas o valor bloqueado deve permanecer em `available_*` até ser liberado/alterado pela plataforma.
            $rawBucket = (float) ($wallet->{$col} ?? 0);
            $wallet->{$col} = round($rawBucket - $amount, 2);
            self::syncAggregateBalance($wallet);
            $wallet->save();

            $withdrawal = Withdrawal::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'amount' => $amount,
                'fee_amount' => $feeCalc['fee'],
                'net_amount' => $feeCalc['net'],
                'bucket' => $bucket,
                'status' => 'pending',
                'notes' => $notes,
                'currency' => 'BRL',
            ]);

            WalletTransaction::query()->create([
                'tenant_id' => $tenantId,
                'order_id' => null,
                'withdrawal_id' => $withdrawal->id,
                'bucket' => $bucket,
                'type' => WalletTransaction::TYPE_WITHDRAWAL_REQUEST,
                'amount_gross' => $amount,
                'amount_fee' => $feeCalc['fee'],
                'amount_net' => $feeCalc['net'],
                'meta' => [
                    'phase' => 'request',
                ],
            ]);

            return $withdrawal->fresh();
        });
    }

    public static function markPaid(Withdrawal $withdrawal): void
    {
        if (! in_array($withdrawal->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return;
        }

        DB::transaction(function () use ($withdrawal) {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
                return;
            }

            $alreadyComplete = WalletTransaction::query()
                ->where('withdrawal_id', $locked->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
                ->exists();

            if ($locked->status !== self::STATUS_PAID) {
                $locked->status = self::STATUS_PAID;
                $locked->save();
            }

            if ($alreadyComplete) {
                return;
            }

            try {
                WalletTransaction::query()->create([
                    'tenant_id' => (int) $locked->tenant_id,
                    'order_id' => null,
                    'withdrawal_id' => $locked->id,
                    'bucket' => (string) $locked->bucket,
                    'type' => WalletTransaction::TYPE_WITHDRAWAL_COMPLETE,
                    'amount_gross' => (float) $locked->amount,
                    'amount_fee' => (float) $locked->fee_amount,
                    'amount_net' => (float) $locked->net_amount,
                    'meta' => ['phase' => 'paid'],
                ]);
            } catch (QueryException $e) {
                if (! self::isDuplicateKey($e)) {
                    throw $e;
                }
            }
        });
    }

    public static function reject(Withdrawal $withdrawal, ?string $adminNote = null): void
    {
        if ($withdrawal->status !== self::STATUS_PENDING) {
            return;
        }

        $tenantId = (int) $withdrawal->tenant_id;
        $bucket = (string) $withdrawal->bucket;
        $col = 'available_'.$bucket;
        if (! in_array($col, ['available_pix', 'available_card', 'available_boleto'], true)) {
            $col = 'available_pix';
        }

        DB::transaction(function () use ($withdrawal, $tenantId, $col, $adminNote) {
            $withdrawal->refresh();
            if ($withdrawal->status !== 'pending') {
                return;
            }

            $gross = (float) $withdrawal->amount;

            $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($wallet !== null && Schema::hasColumn('tenant_wallets', $col)) {
                $wallet->{$col} = round((float) ($wallet->{$col} ?? 0) + $gross, 2);
                self::syncAggregateBalance($wallet);
                $wallet->save();
            }

            $note = trim((string) ($withdrawal->notes ?? ''));
            if ($adminNote !== null && $adminNote !== '') {
                $note .= ($note !== '' ? "\n\n" : '').'[Rejeitado] '.$adminNote;
            }
            $withdrawal->notes = $note !== '' ? $note : null;
            $withdrawal->status = 'rejected';
            $withdrawal->save();

            WalletTransaction::query()->create([
                'tenant_id' => $tenantId,
                'order_id' => null,
                'withdrawal_id' => $withdrawal->id,
                'bucket' => (string) $withdrawal->bucket,
                'type' => WalletTransaction::TYPE_WITHDRAWAL_REFUND,
                'amount_gross' => $gross,
                'amount_fee' => 0,
                'amount_net' => $gross,
                'meta' => ['phase' => 'rejected', 'admin_note' => $adminNote],
            ]);
        });
    }

    private static function syncAggregateBalance(TenantWallet $wallet): void
    {
        if (! Schema::hasColumn('tenant_wallets', 'available_balance')) {
            return;
        }
        $wallet->available_balance = round(
            (float) ($wallet->available_pix ?? 0)
            + (float) ($wallet->available_card ?? 0)
            + (float) ($wallet->available_boleto ?? 0),
            2
        );
    }

    private static function isDuplicateKey(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        return in_array($code, ['1062', '23000', '23505'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
