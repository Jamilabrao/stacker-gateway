<?php

namespace App\Services\CajuPay;

use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

class CajuPayAccountResolver
{
    public function resolveForTenant(?int $tenantId): ?CajuPayAccount
    {
        if ($tenantId !== null && $tenantId > 0) {
            $owner = User::query()
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhere('id', $tenantId);
                })
                ->whereNotNull('cajupay_account_id')
                ->first();

            if ($owner !== null && $owner->cajupay_account_id) {
                $assigned = CajuPayAccount::query()
                    ->whereKey($owner->cajupay_account_id)
                    ->enabled()
                    ->connected()
                    ->first();
                if ($assigned !== null) {
                    return $assigned;
                }
            }
        }

        $default = CajuPayAccount::query()->default()->first();
        if ($default !== null && $default->is_enabled && $this->isUsableAccount($default)) {
            return $default;
        }

        $anyUsable = CajuPayAccount::query()
            ->enabled()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->first(fn (CajuPayAccount $account) => $this->isUsableAccount($account));

        if ($anyUsable !== null) {
            return $anyUsable;
        }

        return $this->legacyCredentialAsVirtualAccount();
    }

    public function resolveForOrder(Order $order): ?CajuPayAccount
    {
        if ($order->cajupay_account_id) {
            $account = CajuPayAccount::query()->whereKey($order->cajupay_account_id)->first();
            if ($account !== null) {
                return $account;
            }
        }

        return $this->resolveForTenant($order->tenant_id);
    }

    public function isAvailableForTenant(?int $tenantId): bool
    {
        $account = $this->resolveForTenant($tenantId);
        if ($account === null) {
            return false;
        }

        return \App\Support\GatewayApiCredentials::isReadyForGateway(
            'cajupay',
            $account->getDecryptedCredentials()
        );
    }

    public function resolveById(int $accountId): ?CajuPayAccount
    {
        return CajuPayAccount::query()->whereKey($accountId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialsForTenant(?int $tenantId): array
    {
        $account = $this->resolveForTenant($tenantId);

        return $account !== null ? $account->getDecryptedCredentials() : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credentialsForOrder(Order $order): ?array
    {
        $account = $this->resolveForOrder($order);
        if ($account === null) {
            return null;
        }

        $credentials = $account->getDecryptedCredentials();

        return $credentials === [] ? null : $credentials;
    }

    public function accountIdForTenant(?int $tenantId): ?int
    {
        return $this->resolveForTenant($tenantId)?->id;
    }

    /**
     * @return Collection<int, CajuPayAccount>
     */
    public function allConnectedForWebhookValidation(): Collection
    {
        $accounts = CajuPayAccount::query()
            ->where('is_connected', true)
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (CajuPayAccount $account) => $this->isUsableAccount($account));

        if ($accounts->isNotEmpty()) {
            return $accounts;
        }

        $legacy = $this->legacyCredentialAsVirtualAccount();

        return $legacy !== null ? collect([$legacy]) : collect();
    }

    public function anyConnectedForPayout(): bool
    {
        if (CajuPayAccount::query()->connected()->enabled()->exists()) {
            return true;
        }

        $legacy = GatewayCredential::forTenant(null)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->enabledForPayments()
            ->exists();

        return $legacy;
    }

    public function defaultOrFirstConnected(): ?CajuPayAccount
    {
        $default = CajuPayAccount::query()->default()->enabled()->first();
        if ($default !== null && $this->isUsableAccount($default)) {
            return $default;
        }

        $any = CajuPayAccount::query()
            ->enabled()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->first(fn (CajuPayAccount $account) => $this->isUsableAccount($account));

        return $any ?? $this->legacyCredentialAsVirtualAccount();
    }

    private function isUsableAccount(CajuPayAccount $account): bool
    {
        if (! $account->is_connected) {
            return false;
        }

        return \App\Support\GatewayApiCredentials::isReadyForGateway(
            'cajupay',
            $account->getDecryptedCredentials()
        );
    }

    /**
     * Fallback durante transição: credencial global legada em gateway_credentials.
     */
    private function legacyCredentialAsVirtualAccount(): ?CajuPayAccount
    {
        $legacy = GatewayCredential::forTenant(null)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->enabledForPayments()
            ->first();

        if ($legacy === null) {
            return null;
        }

        $virtual = new CajuPayAccount([
            'name' => 'Conta legada',
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $virtual->id = 0;
        $virtual->setEncryptedCredentials($legacy->getDecryptedCredentials());

        return $virtual;
    }
}
