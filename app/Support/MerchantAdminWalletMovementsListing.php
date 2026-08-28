<?php

namespace App\Support;

use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\AdminSettlementAnticipateService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

/**
 * Listagem paginada das movimentações de carteira de um tenant (somente leitura).
 */
final class MerchantAdminWalletMovementsListing
{
    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    /** @var list<string> */
    public const SORT_WHITELIST = ['id', 'created_at', 'amount_net', 'type'];

    public static function normalizePerPage(mixed $perPage): int
    {
        $perPage = (int) $perPage;
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            return self::DEFAULT_PER_PAGE;
        }

        return $perPage;
    }

    public static function normalizeSort(mixed $sort): string
    {
        $sort = is_string($sort) ? $sort : 'id';

        return in_array($sort, self::SORT_WHITELIST, true) ? $sort : 'id';
    }

    public static function normalizeDirection(mixed $direction): string
    {
        return strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @return array{
     *     wallet_transactions: LengthAwarePaginator,
     *     filters: array<string, mixed>,
     *     type_options: array<string, string>
     * }
     */
    public static function paginateForTenant(int $tenantId, Request $request): array
    {
        $perPage = self::normalizePerPage($request->query('wallet_per_page'));
        $sort = self::normalizeSort($request->query('wallet_sort'));
        $direction = self::normalizeDirection($request->query('wallet_direction'));
        $type = trim((string) $request->query('wallet_type', 'all'));
        $q = trim((string) $request->query('wallet_q', ''));
        $dateFrom = trim((string) $request->query('wallet_date_from', ''));
        $dateTo = trim((string) $request->query('wallet_date_to', ''));

        $labels = WalletTransaction::typeLabels();
        if ($type !== 'all' && ! array_key_exists($type, $labels)) {
            $type = 'all';
        }

        $empty = new LengthAwarePaginator([], 0, $perPage, max(1, (int) $request->query('wallet_page', 1)), [
            'path' => $request->url(),
            'pageName' => 'wallet_page',
            'query' => $request->query(),
        ]);

        $filters = [
            'wallet_type' => $type,
            'wallet_q' => $q !== '' ? $q : null,
            'wallet_date_from' => $dateFrom !== '' ? $dateFrom : null,
            'wallet_date_to' => $dateTo !== '' ? $dateTo : null,
            'wallet_per_page' => $perPage,
            'wallet_sort' => $sort,
            'wallet_direction' => $direction,
        ];

        if ($tenantId <= 0 || ! Schema::hasTable('wallet_transactions')) {
            return [
                'wallet_transactions' => $empty,
                'filters' => $filters,
                'type_options' => $labels,
            ];
        }

        $query = WalletTransaction::query()->where('tenant_id', $tenantId);

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        if ($q !== '') {
            if (ctype_digit($q)) {
                $id = (int) $q;
                $query->where(function ($qq) use ($id) {
                    $qq->where('id', $id)
                        ->orWhere('order_id', $id)
                        ->orWhere('withdrawal_id', $id);
                });
            } else {
                $query->whereRaw('0 = 1');
            }
        }

        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderByDesc('id');
        }

        $paginator = $query
            ->paginate($perPage, ['*'], 'wallet_page')
            ->withQueryString();

        $orderIds = $paginator->getCollection()
            ->pluck('order_id')
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $orderStatuses = [];
        if ($orderIds !== [] && Schema::hasTable('orders')) {
            $orderStatuses = Order::query()->whereIn('id', $orderIds)->pluck('status', 'id')->all();
        }

        $paginator->through(function (WalletTransaction $t) use ($labels, $orderStatuses) {
            $meta = is_array($t->meta) ? $t->meta : [];
            $settlement = self::settlementPayload($t, $meta);
            $orderStatus = isset($t->order_id) ? ($orderStatuses[(int) $t->order_id] ?? null) : null;
            $anticipated = isset($meta['anticipated_at']) && is_string($meta['anticipated_at']) && $meta['anticipated_at'] !== '';
            $typeLabel = $labels[$t->type] ?? $t->type;
            if ($t->type === WalletTransaction::TYPE_CREDIT_SALE_PENDING && $anticipated) {
                $typeLabel = $labels[WalletTransaction::TYPE_CREDIT_SALE] ?? 'Venda creditada';
            }

            return [
                'id' => $t->id,
                'type' => $t->type,
                'type_label' => $typeLabel,
                'bucket' => $t->bucket,
                'amount_net' => (float) $t->amount_net,
                'order_id' => $t->order_id,
                'withdrawal_id' => $t->withdrawal_id,
                'note' => $meta['note'] ?? null,
                'created_at' => $t->created_at?->toIso8601String(),
                'clears_at' => $settlement['clears_at'],
                'released_at' => $settlement['released_at'],
                'settlement_status' => $settlement['status'],
                'settlement_at' => $settlement['at'],
                'can_anticipate' => AdminSettlementAnticipateService::canAnticipate(
                    $t,
                    $meta,
                    is_string($orderStatus) ? $orderStatus : null
                ),
            ];
        });

        return [
            'wallet_transactions' => $paginator,
            'filters' => $filters,
            'type_options' => $labels,
        ];
    }

    /**
     * Data em que o valor entra no saldo disponível do seller (clears_at).
     *
     * @param  array<string, mixed>  $meta
     * @return array{clears_at: ?string, released_at: ?string, status: ?string, at: ?string}
     */
    public static function settlementPayload(WalletTransaction $t, array $meta): array
    {
        $clearsAt = isset($meta['clears_at']) && is_string($meta['clears_at']) && $meta['clears_at'] !== ''
            ? $meta['clears_at']
            : null;
        $releasedAt = isset($meta['released_at']) && is_string($meta['released_at']) && $meta['released_at'] !== ''
            ? $meta['released_at']
            : null;
        $released = $releasedAt !== null;

        if ($t->type === WalletTransaction::TYPE_CREDIT_SALE || $released) {
            return [
                'clears_at' => $clearsAt,
                'released_at' => $releasedAt,
                'status' => 'available',
                'at' => $releasedAt ?? $clearsAt,
            ];
        }

        if ($t->type === WalletTransaction::TYPE_CREDIT_SALE_PENDING) {
            return [
                'clears_at' => $clearsAt,
                'released_at' => $releasedAt,
                'status' => 'pending',
                'at' => $clearsAt,
            ];
        }

        return [
            'clears_at' => $clearsAt,
            'released_at' => $releasedAt,
            'status' => null,
            'at' => null,
        ];
    }

    /**
     * Paginator vazio (quando a aba carteira não está ativa).
     */
    public static function emptyPaginator(Request $request): LengthAwarePaginator
    {
        $perPage = self::normalizePerPage($request->query('wallet_per_page'));

        return new LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => $request->url(),
            'pageName' => 'wallet_page',
            'query' => $request->query(),
        ]);
    }
}
