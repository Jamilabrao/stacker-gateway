<?php

namespace App\Services\Api;

use App\Jobs\ProcessWithdrawalPayoutJob;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\MerchantOperationalGuard;
use App\Services\MerchantWithdrawalService;
use App\Services\Payout\PayoutDestinationValidator;
use App\Services\Withdrawal\WithdrawalPolicyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ApiWithdrawalService
{
    public function __construct(
        private ApiWithdrawalWebhookService $webhookService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function createWithdrawal(
        User $owner,
        float $amount,
        string $bucket,
        int $apiApplicationId,
        ?int $apiKeyId = null,
        ?string $notes = null,
    ): Withdrawal {
        $this->assertVelocityLimits((int) $owner->tenant_id);

        $destination = PayoutDestinationValidator::assertReadyForWithdrawal($owner);
        if (! ($destination['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'amount' => $destination['message'],
            ]);
        }

        $withdrawal = MerchantWithdrawalService::requestWithdrawal($owner, $amount, $bucket, $notes);

        if (Schema::hasColumn($withdrawal->getTable(), 'api_application_id')) {
            $withdrawal->forceFill([
                'api_application_id' => $apiApplicationId,
                'api_key_id' => $apiKeyId,
            ])->save();
        }

        $this->webhookService->dispatchForWithdrawal($withdrawal->fresh(), 'withdrawal.created');

        if (WithdrawalPolicyService::autoWithdrawalEnabled()) {
            ProcessWithdrawalPayoutJob::dispatch($withdrawal->id);
        }

        return $withdrawal->fresh();
    }

    private function assertVelocityLimits(int $tenantId): void
    {
        $maxPerDay = (int) config('getfy.api.withdrawals.max_per_day', 50);
        $maxAmountPerDay = (float) config('getfy.api.withdrawals.max_amount_per_day', 500000);

        $cacheKey = 'api_withdrawal_velocity:'.$tenantId.':'.now()->format('Y-m-d');
        $stats = Cache::get($cacheKey, ['count' => 0, 'amount' => 0.0]);

        if ((int) ($stats['count'] ?? 0) >= $maxPerDay) {
            throw ValidationException::withMessages([
                'amount' => 'Limite diário de saques via API atingido.',
            ]);
        }

        if ((float) ($stats['amount'] ?? 0) >= $maxAmountPerDay) {
            throw ValidationException::withMessages([
                'amount' => 'Limite diário de valor de saques via API atingido.',
            ]);
        }
    }

    public function recordVelocity(int $tenantId, float $amount): void
    {
        $cacheKey = 'api_withdrawal_velocity:'.$tenantId.':'.now()->format('Y-m-d');
        $stats = Cache::get($cacheKey, ['count' => 0, 'amount' => 0.0]);
        Cache::put($cacheKey, [
            'count' => (int) ($stats['count'] ?? 0) + 1,
            'amount' => round((float) ($stats['amount'] ?? 0) + $amount, 2),
        ], now()->endOfDay());
    }

    public static function resolveInfoprodutor(int $tenantId): User
    {
        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();

        if ($user === null) {
            $user = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
        }

        if ($user === null) {
            throw ValidationException::withMessages(['amount' => 'Conta de infoprodutor não encontrada.']);
        }

        MerchantOperationalGuard::assertCanRequestWithdrawal($user);

        return $user;
    }
}
