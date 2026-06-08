<?php

namespace App\Services\Checkout;

use App\Models\CheckoutSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CheckoutAbandonmentMetrics
{
    /**
     * Contagem deduplicada: 1 por (tenant_id, product_id, e-mail) no período.
     *
     * @param  list<int|string>|null  $productIds
     */
    public function countValidAbandoned(?int $tenantId, ?array $productIds, ?string $start, ?string $end): int
    {
        return count($this->deduplicatedSessionIds($tenantId, $productIds, $start, $end));
    }

    /**
     * Sessões válidas mais recentes para tabela de recuperação (deduplicadas).
     *
     * @param  list<int|string>|null  $productIds
     * @return Collection<int, CheckoutSession>
     */
    public function latestValidAbandonedSessions(?int $tenantId, ?array $productIds, ?string $start, ?string $end, int $limit = 20): Collection
    {
        $ids = $this->deduplicatedSessionIds($tenantId, $productIds, $start, $end);

        if ($ids === []) {
            return collect();
        }

        $activity = CheckoutSession::lastActivitySql();

        return CheckoutSession::query()
            ->whereIn('id', $ids)
            ->with('product:id,name')
            ->orderByRaw("{$activity} DESC")
            ->limit($limit)
            ->get();
    }

    /**
     * Query de sessões abandonadas válidas e deduplicadas (export CSV).
     *
     * @param  list<int|string>|null  $productIds
     * @return Builder<CheckoutSession>
     */
    public function validAbandonedQuery(?int $tenantId, ?array $productIds, ?string $start, ?string $end): Builder
    {
        $ids = $this->deduplicatedSessionIds($tenantId, $productIds, $start, $end);
        $activity = CheckoutSession::lastActivitySql();

        return CheckoutSession::query()
            ->whereIn('id', $ids === [] ? [-1] : $ids)
            ->orderByRaw("{$activity} DESC");
    }

    /**
     * @param  list<int|string>|null  $productIds
     * @return list<int>
     */
    private function deduplicatedSessionIds(?int $tenantId, ?array $productIds, ?string $start, ?string $end): array
    {
        return $this->baseValidBuilder($tenantId, $productIds, $start, $end)
            ->get()
            ->groupBy(fn (CheckoutSession $session) => $session->tenant_id
                .'|'.$session->product_id
                .'|'.strtolower(trim((string) $session->email)))
            ->map(fn (Collection $group) => $group
                ->sortByDesc(fn (CheckoutSession $session) => $session->lastActivityAt()->timestamp)
                ->first()
                ?->id)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>|null  $productIds
     * @return Builder<CheckoutSession>
     */
    private function baseValidBuilder(?int $tenantId, ?array $productIds, ?string $start, ?string $end): Builder
    {
        $query = CheckoutSession::query()
            ->forTenant($tenantId)
            ->whereAbandonmentValid()
            ->whereAbandonmentActivityBetween($start, $end);

        if ($productIds !== null) {
            $query->whereIn('product_id', $productIds);
        }

        return $query;
    }
}
