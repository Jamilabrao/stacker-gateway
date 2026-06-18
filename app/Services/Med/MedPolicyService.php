<?php

namespace App\Services\Med;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;

class MedPolicyService
{
    public const SETTING_MED_ZERO = 'med_zero_enabled';

    public function isApiPixRestOrder(Order $order): bool
    {
        if ($order->api_application_id === null) {
            return false;
        }

        if ($order->payment_method !== 'pix') {
            return false;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];

        return ($meta['source'] ?? null) === 'api';
    }

    public function responsiblePartyForOrder(Order $order): string
    {
        if ($this->isApiPixRestOrder($order) && ! $this->medZeroForTenant((int) $order->tenant_id)) {
            return MedDispute::PARTY_TENANT;
        }

        return MedDispute::PARTY_PLATFORM;
    }

    public function medZeroForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        $raw = Setting::get(self::SETTING_MED_ZERO, null, $tenantId);
        if ($raw === null || $raw === '') {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public function setMedZeroForTenant(int $tenantId, bool $enabled): void
    {
        if ($tenantId <= 0) {
            return;
        }

        if (! $enabled) {
            Setting::query()
                ->where('key', self::SETTING_MED_ZERO)
                ->where('tenant_id', $tenantId)
                ->delete();

            return;
        }

        Setting::set(self::SETTING_MED_ZERO, true, $tenantId);
    }

    public function shouldHoldTenantBalance(MedDispute $dispute): bool
    {
        return $dispute->responsible_party === MedDispute::PARTY_TENANT;
    }

    public function orderOriginLabel(Order $order): string
    {
        return $this->isApiPixRestOrder($order) ? 'API PIX REST' : 'Checkout';
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeApiPixRestOrders(Builder $query): Builder
    {
        return $query
            ->whereNotNull('api_application_id')
            ->where('payment_method', 'pix')
            ->where('metadata->source', 'api');
    }
}
