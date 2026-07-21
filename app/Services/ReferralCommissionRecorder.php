<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ReferralCommission;
use App\Models\ReferralWallet;
use App\Models\ReferralWalletTransaction;
use App\Models\User;
use App\Support\ReferralProgramSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReferralCommissionRecorder
{
    public static function recordForOrder(Order $order): ?ReferralCommission
    {
        if (! ReferralProgramSettings::isEnabled()) {
            return null;
        }

        if (! Schema::hasTable('referral_commissions') || ! Schema::hasTable('referral_wallets')) {
            return null;
        }

        if ((string) $order->status !== 'completed') {
            return null;
        }

        $referredTenantId = (int) ($order->tenant_id ?? 0);
        if ($referredTenantId < 1) {
            return null;
        }

        $referred = User::query()->find($referredTenantId);
        if ($referred === null || ! $referred->referred_by_user_id || ! $referred->referred_at) {
            return null;
        }

        $referrerId = (int) $referred->referred_by_user_id;
        if ($referrerId < 1 || $referrerId === (int) $referred->id) {
            return null;
        }

        if (! self::isWithinEligibilityWindow($referred)) {
            return null;
        }

        $referrer = User::query()->find($referrerId);
        if ($referrer === null || ! self::isEligibleReferrer($referrer)) {
            return null;
        }

        $existing = ReferralCommission::query()
            ->where('order_id', $order->id)
            ->where('referrer_user_id', $referrerId)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $breakdown = OrderFeeBreakdownService::forOrder($order, $referredTenantId);
        $platformFee = round((float) ($breakdown['fee'] ?? 0), 2);
        if ($platformFee < 0.01) {
            return null;
        }

        $percent = ReferralProgramSettings::commissionPercentForReferrer($referrer);
        if ($percent <= 0) {
            return null;
        }

        $amount = round($platformFee * ($percent / 100.0), 2);
        if ($amount < 0.01) {
            return null;
        }
        if ($amount > $platformFee) {
            $amount = $platformFee;
        }

        $reference = 'refc:o'.$order->id.':u'.$referrerId;

        try {
            return DB::transaction(function () use (
                $order,
                $referred,
                $referrerId,
                $platformFee,
                $percent,
                $amount,
                $reference,
                $breakdown
            ) {
                $dup = ReferralCommission::query()
                    ->where('order_id', $order->id)
                    ->where('referrer_user_id', $referrerId)
                    ->lockForUpdate()
                    ->first();
                if ($dup !== null) {
                    return $dup;
                }

                if (ReferralWalletTransaction::query()->where('reference', $reference)->exists()) {
                    return ReferralCommission::query()
                        ->where('order_id', $order->id)
                        ->where('referrer_user_id', $referrerId)
                        ->first();
                }

                $commission = ReferralCommission::query()->create([
                    'order_id' => $order->id,
                    'referrer_user_id' => $referrerId,
                    'referred_user_id' => $referred->id,
                    'platform_fee' => $platformFee,
                    'commission_percent' => $percent,
                    'amount' => $amount,
                    'status' => ReferralCommission::STATUS_AVAILABLE,
                    'meta' => [
                        'from_wallet' => (bool) ($breakdown['from_wallet'] ?? false),
                    ],
                ]);

                $wallet = ReferralWallet::query()
                    ->where('user_id', $referrerId)
                    ->lockForUpdate()
                    ->first();
                if ($wallet === null) {
                    $wallet = ReferralWallet::forUser($referrerId);
                    $wallet = ReferralWallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
                }

                $wallet->available_balance = round((float) $wallet->available_balance + $amount, 2);
                $wallet->lifetime_earned = round((float) $wallet->lifetime_earned + $amount, 2);
                $wallet->save();

                ReferralWalletTransaction::query()->create([
                    'user_id' => $referrerId,
                    'referral_commission_id' => $commission->id,
                    'type' => ReferralWalletTransaction::TYPE_CREDIT_COMMISSION,
                    'reference' => $reference,
                    'amount' => $amount,
                    'balance_after' => (float) $wallet->available_balance,
                    'meta' => [
                        'order_id' => $order->id,
                        'referred_user_id' => $referred->id,
                    ],
                ]);

                return $commission;
            });
        } catch (\Throwable $e) {
            report($e);

            return ReferralCommission::query()
                ->where('order_id', $order->id)
                ->where('referrer_user_id', $referrerId)
                ->first();
        }
    }

    public static function reverseForOrder(Order $order): void
    {
        if (! Schema::hasTable('referral_commissions')) {
            return;
        }

        $commissions = ReferralCommission::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', ReferralCommission::STATUS_REVERSED)
            ->get();

        foreach ($commissions as $commission) {
            self::reverseCommission($commission);
        }
    }

    public static function reverseCommission(ReferralCommission $commission): void
    {
        if ($commission->status === ReferralCommission::STATUS_REVERSED) {
            return;
        }

        $amount = round((float) $commission->amount, 2);
        if ($amount <= 0) {
            $commission->status = ReferralCommission::STATUS_REVERSED;
            $commission->save();

            return;
        }

        $reference = 'refc-rev:c'.$commission->id;

        DB::transaction(function () use ($commission, $amount, $reference) {
            $locked = ReferralCommission::query()->whereKey($commission->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status === ReferralCommission::STATUS_REVERSED) {
                return;
            }

            if (ReferralWalletTransaction::query()->where('reference', $reference)->exists()) {
                $locked->status = ReferralCommission::STATUS_REVERSED;
                $locked->save();

                return;
            }

            $wallet = ReferralWallet::query()
                ->where('user_id', $locked->referrer_user_id)
                ->lockForUpdate()
                ->first();
            if ($wallet === null) {
                $wallet = ReferralWallet::forUser((int) $locked->referrer_user_id);
                $wallet = ReferralWallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            $available = (float) $wallet->available_balance;
            $debited = min($available, $amount);
            $residual = round($amount - $debited, 2);

            $wallet->available_balance = round($available - $debited, 2);
            $wallet->save();

            ReferralWalletTransaction::query()->create([
                'user_id' => $locked->referrer_user_id,
                'referral_commission_id' => $locked->id,
                'type' => ReferralWalletTransaction::TYPE_DEBIT_REVERSAL,
                'reference' => $reference,
                'amount' => -$debited,
                'balance_after' => (float) $wallet->available_balance,
                'meta' => [
                    'order_id' => $locked->order_id,
                    'requested' => $amount,
                    'debited' => $debited,
                    'residual_debt' => $residual > 0 ? $residual : 0,
                ],
            ]);

            $meta = is_array($locked->meta) ? $locked->meta : [];
            if ($residual > 0) {
                $meta['residual_debt'] = $residual;
            }
            $locked->meta = $meta;
            $locked->status = ReferralCommission::STATUS_REVERSED;
            $locked->save();
        });
    }

    public static function isWithinEligibilityWindow(User $referred): bool
    {
        if (! $referred->referred_at) {
            return false;
        }

        $days = ReferralProgramSettings::eligibilityDays();
        if ($days === 0) {
            return true;
        }

        return $referred->referred_at->copy()->addDays($days)->isFuture()
            || $referred->referred_at->copy()->addDays($days)->isToday();
    }

    public static function isEligibleReferrer(User $referrer): bool
    {
        if (! $referrer->canAccessSellerPanel() && ! $referrer->isInfoprodutor()) {
            return false;
        }

        $status = (string) ($referrer->account_status ?? 'approved');
        if (in_array($status, ['blocked', 'rejected', 'suspended'], true)) {
            return false;
        }

        return true;
    }

    public static function ensureReferralCode(User $user): string
    {
        if (! Schema::hasColumn('users', 'referral_code')) {
            return '';
        }

        if (is_string($user->referral_code) && $user->referral_code !== '') {
            return $user->referral_code;
        }

        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(Str::random(8));
            if (! User::query()->where('referral_code', $code)->exists()) {
                $user->forceFill(['referral_code' => $code])->save();

                return $code;
            }
        }

        $code = strtoupper(Str::random(10)).$user->id;
        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }
}
