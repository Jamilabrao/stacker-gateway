<?php

namespace App\Services;

use App\Models\ReferralWallet;
use App\Models\ReferralWalletTransaction;
use App\Models\ReferralWithdrawal;
use App\Models\User;
use App\Support\MerchantProfileSnapshot;
use App\Support\ReferralProgramSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReferralWithdrawalService
{
    /**
     * @throws ValidationException
     */
    public static function request(User $user, float $amount, ?string $notes = null): ReferralWithdrawal
    {
        if (! ReferralProgramSettings::isEnabled()) {
            throw ValidationException::withMessages(['amount' => 'O programa Indique e Ganhe está desativado.']);
        }

        if (! Schema::hasTable('referral_withdrawals')) {
            throw ValidationException::withMessages(['amount' => 'Saques de indicação indisponíveis.']);
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }

        $min = ReferralProgramSettings::minWithdrawal();
        if ($amount + 0.0001 < $min) {
            throw ValidationException::withMessages([
                'amount' => 'O valor mínimo para saque é R$ '.number_format($min, 2, ',', '.').'.',
            ]);
        }

        $pix = self::pixSnapshotFor($user);
        if ($pix === null) {
            throw ValidationException::withMessages([
                'amount' => 'Cadastre uma chave PIX em Configurações / Financeiro antes de sacar.',
            ]);
        }

        return DB::transaction(function () use ($user, $amount, $notes, $pix) {
            $wallet = ReferralWallet::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($wallet === null) {
                $wallet = ReferralWallet::forUser((int) $user->id);
                $wallet = ReferralWallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            $available = (float) $wallet->available_balance;
            if ($available + 0.0001 < $amount) {
                throw ValidationException::withMessages(['amount' => 'Saldo de indicação insuficiente.']);
            }

            $wallet->available_balance = round($available - $amount, 2);
            $wallet->save();

            $withdrawal = ReferralWithdrawal::query()->create([
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => ReferralWithdrawal::STATUS_PENDING,
                'notes' => $notes,
                'currency' => 'BRL',
                'pix_snapshot' => $pix,
            ]);

            ReferralWalletTransaction::query()->create([
                'user_id' => $user->id,
                'referral_withdrawal_id' => $withdrawal->id,
                'type' => ReferralWalletTransaction::TYPE_DEBIT_WITHDRAWAL,
                'reference' => 'refw:req:'.$withdrawal->id,
                'amount' => -$amount,
                'balance_after' => (float) $wallet->available_balance,
                'meta' => ['status' => ReferralWithdrawal::STATUS_PENDING],
            ]);

            return $withdrawal;
        });
    }

    public static function markPaid(ReferralWithdrawal $withdrawal, User $admin, ?string $notes = null): ReferralWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $admin, $notes) {
            $locked = ReferralWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw ValidationException::withMessages(['status' => 'Saque não encontrado.']);
            }
            if (! in_array($locked->status, [ReferralWithdrawal::STATUS_PENDING, ReferralWithdrawal::STATUS_PROCESSING], true)) {
                throw ValidationException::withMessages(['status' => 'Este saque não pode ser marcado como pago.']);
            }

            $locked->status = ReferralWithdrawal::STATUS_PAID;
            $locked->reviewed_by_user_id = $admin->id;
            $locked->reviewed_at = now();
            $locked->paid_at = now();
            if ($notes !== null && trim($notes) !== '') {
                $locked->notes = trim($notes);
            }
            $locked->save();

            $wallet = ReferralWallet::query()->where('user_id', $locked->user_id)->lockForUpdate()->first();
            if ($wallet !== null) {
                $wallet->lifetime_withdrawn = round((float) $wallet->lifetime_withdrawn + (float) $locked->amount, 2);
                $wallet->save();
            }

            return $locked->fresh();
        });
    }

    public static function reject(ReferralWithdrawal $withdrawal, User $admin, ?string $reason = null): ReferralWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $admin, $reason) {
            $locked = ReferralWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw ValidationException::withMessages(['status' => 'Saque não encontrado.']);
            }
            if (! in_array($locked->status, [ReferralWithdrawal::STATUS_PENDING, ReferralWithdrawal::STATUS_PROCESSING], true)) {
                throw ValidationException::withMessages(['status' => 'Este saque não pode ser rejeitado.']);
            }

            $amount = round((float) $locked->amount, 2);
            $wallet = ReferralWallet::query()->where('user_id', $locked->user_id)->lockForUpdate()->first();
            if ($wallet === null) {
                $wallet = ReferralWallet::forUser((int) $locked->user_id);
                $wallet = ReferralWallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            $wallet->available_balance = round((float) $wallet->available_balance + $amount, 2);
            $wallet->save();

            ReferralWalletTransaction::query()->create([
                'user_id' => $locked->user_id,
                'referral_withdrawal_id' => $locked->id,
                'type' => ReferralWalletTransaction::TYPE_WITHDRAWAL_REFUND,
                'reference' => 'refw:rej:'.$locked->id,
                'amount' => $amount,
                'balance_after' => (float) $wallet->available_balance,
                'meta' => ['reason' => $reason],
            ]);

            $locked->status = ReferralWithdrawal::STATUS_REJECTED;
            $locked->failed_reason = $reason;
            $locked->reviewed_by_user_id = $admin->id;
            $locked->reviewed_at = now();
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pixSnapshotFor(User $user): ?array
    {
        $profile = MerchantProfileSnapshot::forUser($user);
        $key = trim((string) ($profile['payout_pix_key'] ?? ''));
        if ($key === '') {
            return null;
        }

        return [
            'pix_key' => $key,
            'pix_key_type' => $profile['payout_pix_key_type'] ?? null,
            'pix_key_type_label' => $profile['payout_pix_key_type_label'] ?? null,
            'pix_label' => $profile['payout_pix_label'] ?? null,
            'pix_owner_document' => $profile['payout_pix_owner_document'] ?? null,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
