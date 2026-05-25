<?php

namespace App\Services;

use App\Models\EmailCampaign;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

class EmailCampaignRecipientsService
{
    /**
     * @param  array<string, mixed>  $filterConfig
     * @return Collection<int, array{email: string, user_id: int|null, name: string, type: string}>
     */
    public function getRecipients(?int $tenantId, array $filterConfig): Collection
    {
        $filterConfig = self::normalizeFilterConfig($filterConfig);
        $byEmail = [];

        if ($filterConfig['include_customers']) {
            foreach ($this->customerRecipients($tenantId, $filterConfig) as $row) {
                $byEmail[$row['email']] = $row;
            }
        }

        if ($filterConfig['include_infoprodutors']) {
            foreach ($this->infoprodutorRecipients($tenantId) as $row) {
                if (! isset($byEmail[$row['email']])) {
                    $byEmail[$row['email']] = $row;
                }
            }
        }

        return collect(array_values($byEmail));
    }

    /**
     * @return Collection<int, array{email: string, user_id: int|null, name: string}>
     */
    public function getNextRecipientsForCampaign(EmailCampaign $campaign, int $limit = 30): Collection
    {
        $filterConfig = $campaign->filter_config ?? [];
        $tenantId = $campaign->tenant_id;
        $all = $this->getRecipients($tenantId, $filterConfig);

        $sentEmails = $campaign->emailCampaignSends()->pluck('email')->flip();
        $pending = $all->filter(fn ($r) => ! $sentEmails->has($r['email']));

        return $pending->take($limit)->values();
    }

    /**
     * @param  array<string, mixed>  $filterConfig
     * @return array{include_customers: bool, include_infoprodutors: bool, all_customers: bool, product_ids: list<int>}
     */
    public static function normalizeFilterConfig(array $filterConfig): array
    {
        $hasNewKeys = array_key_exists('include_customers', $filterConfig)
            || array_key_exists('include_infoprodutors', $filterConfig);

        if (! $hasNewKeys) {
            $includeCustomers = true;
            $includeInfoprodutors = false;
        } else {
            $includeCustomers = filter_var($filterConfig['include_customers'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $includeInfoprodutors = filter_var($filterConfig['include_infoprodutors'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        if (! $includeCustomers && ! $includeInfoprodutors) {
            $includeCustomers = true;
        }

        $allCustomers = filter_var($filterConfig['all_customers'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $productIds = [];
        if (! empty($filterConfig['product_ids']) && is_array($filterConfig['product_ids'])) {
            foreach ($filterConfig['product_ids'] as $id) {
                if (is_numeric($id)) {
                    $productIds[] = (int) $id;
                }
            }
        }

        if ($includeCustomers && $productIds === [] && ! $allCustomers) {
            $allCustomers = true;
        }

        return [
            'include_customers' => $includeCustomers,
            'include_infoprodutors' => $includeInfoprodutors,
            'all_customers' => $allCustomers,
            'product_ids' => $productIds,
        ];
    }

    /**
     * @param  array{include_customers: bool, include_infoprodutors: bool, all_customers: bool, product_ids: list<int>}  $filterConfig
     * @return list<array{email: string, user_id: int|null, name: string, type: string}>
     */
    private function customerRecipients(?int $tenantId, array $filterConfig): array
    {
        $query = Order::query()
            ->where('status', 'completed')
            ->whereNotNull('email')
            ->where('email', '!=', '');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if (! $filterConfig['all_customers'] && $filterConfig['product_ids'] !== []) {
            $query->whereIn('product_id', $filterConfig['product_ids']);
        }

        $orders = $query->with('user:id,name,email')->get();
        $byEmail = [];
        foreach ($orders as $order) {
            $email = strtolower(trim((string) $order->email));
            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }
            if (isset($byEmail[$email])) {
                continue;
            }
            $name = trim((string) ($order->user?->name ?? ''));
            $byEmail[$email] = [
                'email' => $email,
                'user_id' => $order->user_id,
                'name' => $name !== '' ? $name : $email,
                'type' => 'customer',
            ];
        }

        return array_values($byEmail);
    }

    /**
     * @return list<array{email: string, user_id: int|null, name: string, type: string}>
     */
    private function infoprodutorRecipients(?int $tenantId): array
    {
        $query = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if ($tenantId !== null) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('id', $tenantId)->orWhere('tenant_id', $tenantId);
            });
        }

        $users = $query->get(['id', 'name', 'email', 'account_status']);
        $byEmail = [];
        foreach ($users as $user) {
            if (in_array((string) ($user->account_status ?? 'approved'), ['suspended', 'blocked', 'rejected'], true)) {
                continue;
            }
            $email = strtolower(trim((string) $user->email));
            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }
            if (isset($byEmail[$email])) {
                continue;
            }
            $name = trim((string) $user->name);
            $byEmail[$email] = [
                'email' => $email,
                'user_id' => $user->id,
                'name' => $name !== '' ? $name : $email,
                'type' => 'infoprodutor',
            ];
        }

        return array_values($byEmail);
    }
}
