<?php

namespace App\Support;

use App\Models\Order;
use App\Models\PanelPushSubscription;
use App\Models\User;
use App\Models\PanelPushCampaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve destinatários de campanhas push administrativas.
 */
final class PanelPushAudienceResolver
{
    /**
     * @return Collection<int, PanelPushSubscription>
     */
    public static function subscriptionsForCampaign(PanelPushCampaign $campaign): Collection
    {
        $filters = is_array($campaign->audience_filters) ? $campaign->audience_filters : [];
        $tenantIds = self::tenantIdsForAudience($campaign->audience, $filters);

        $query = PanelPushSubscription::query()->with('user:id,role,account_status,tenant_id');

        if ($tenantIds !== null) {
            if ($tenantIds === []) {
                return collect();
            }
            $query->whereIn('tenant_id', $tenantIds);
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>|null null = sem filtro de tenant (todos inscritos)
     */
    public static function tenantIdsForAudience(string $audience, array $filters): ?array
    {
        return match ($audience) {
            PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS => null,
            PanelPushCampaign::AUDIENCE_ALL_MERCHANTS,
            PanelPushCampaign::AUDIENCE_ACTIVE_MERCHANTS => self::merchantTenantIds(
                onlyActive: $audience === PanelPushCampaign::AUDIENCE_ACTIVE_MERCHANTS
            ),
            PanelPushCampaign::AUDIENCE_SELECTED => self::selectedTenantIds($filters),
            PanelPushCampaign::AUDIENCE_WITH_SALES => self::salesPeriodTenantIds($filters, withSales: true),
            PanelPushCampaign::AUDIENCE_WITHOUT_SALES => self::salesPeriodTenantIds($filters, withSales: false),
            PanelPushCampaign::AUDIENCE_ACCOUNT_MANAGER => self::accountManagerTenantIds($filters),
            default => self::merchantTenantIds(onlyActive: false),
        };
    }

    /**
     * @return list<int>
     */
    private static function merchantTenantIds(bool $onlyActive): array
    {
        $q = User::query()->where('role', User::ROLE_INFOPRODUTOR);
        if ($onlyActive) {
            $q->where('account_status', 'approved');
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private static function selectedTenantIds(array $filters): array
    {
        $ids = $filters['merchant_ids'] ?? [];
        if (! is_array($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private static function salesPeriodTenantIds(array $filters, bool $withSales): array
    {
        if (! Schema::hasTable('orders')) {
            return $withSales ? [] : self::merchantTenantIds(onlyActive: true);
        }

        $from = (string) ($filters['sales_from'] ?? '');
        $to = (string) ($filters['sales_to'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return [];
        }

        $withSalesIds = Order::query()
            ->where('status', 'completed')
            ->whereDate('updated_at', '>=', $from)
            ->whereDate('updated_at', '<=', $to)
            ->distinct()
            ->pluck('tenant_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($withSales) {
            return $withSalesIds;
        }

        $all = self::merchantTenantIds(onlyActive: true);

        return array_values(array_diff($all, $withSalesIds));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private static function accountManagerTenantIds(array $filters): array
    {
        $managerId = (int) ($filters['account_manager_id'] ?? 0);
        if ($managerId <= 0 || ! Schema::hasColumn('users', 'account_manager_id')) {
            return [];
        }

        return User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where('account_manager_id', $managerId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
