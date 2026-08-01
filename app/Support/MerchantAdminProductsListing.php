<?php

namespace App\Support;

use App\Models\Product;
use App\Services\ProductDeliverablePreviewService;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Listagem admin de produtos de um infoprodutor (tenant).
 */
final class MerchantAdminProductsListing
{
    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    /** @var list<string> */
    public const SORT_WHITELIST = [
        'name',
        'created_at',
        'updated_at',
        'sales_count',
        'sales_total',
        'approval_status',
        'is_active',
        'price',
    ];

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
        $sort = is_string($sort) ? $sort : 'created_at';

        return in_array($sort, self::SORT_WHITELIST, true) ? $sort : 'created_at';
    }

    public static function normalizeDirection(mixed $direction): string
    {
        return strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';
    }

    public static function totalCount(int $tenantId): int
    {
        if ($tenantId <= 0 || ! Schema::hasTable('products')) {
            return 0;
        }

        return (int) Product::query()->where('tenant_id', $tenantId)->count();
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     pending: int,
     *     rejected: int
     * }
     */
    public static function summary(int $tenantId): array
    {
        $empty = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'pending' => 0,
            'rejected' => 0,
        ];

        if ($tenantId <= 0 || ! Schema::hasTable('products')) {
            return $empty;
        }

        $approvalReady = Schema::hasColumn('products', 'approval_status');

        // PostgreSQL: is_active é boolean (não comparar com 1/0). SQLite: 0/1.
        $activeExpr = DB::getDriverName() === 'pgsql'
            ? 'CASE WHEN is_active IS TRUE THEN 1 ELSE 0 END'
            : 'CASE WHEN is_active = 1 THEN 1 ELSE 0 END';
        $inactiveExpr = DB::getDriverName() === 'pgsql'
            ? 'CASE WHEN is_active IS NOT TRUE THEN 1 ELSE 0 END'
            : 'CASE WHEN is_active = 0 OR is_active IS NULL THEN 1 ELSE 0 END';

        $row = Product::query()
            ->where('tenant_id', $tenantId)
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM({$activeExpr}) as active_count")
            ->selectRaw("SUM({$inactiveExpr}) as inactive_count")
            ->when($approvalReady, function ($q) {
                $q->selectRaw("SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_count")
                    ->selectRaw("SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected_count");
            })
            ->first();

        if (! $row) {
            return $empty;
        }

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active_count ?? 0),
            'inactive' => (int) ($row->inactive_count ?? 0),
            'pending' => $approvalReady ? (int) ($row->pending_count ?? 0) : 0,
            'rejected' => $approvalReady ? (int) ($row->rejected_count ?? 0) : 0,
        ];
    }

    /**
     * @return array{
     *     products: LengthAwarePaginator,
     *     filters: array<string, mixed>,
     *     summary: array<string, int>,
     *     approval_enabled: bool,
     *     type_options: list<array{value: string, label: string}>
     * }
     */
    public static function paginateForTenant(int $tenantId, Request $request): array
    {
        $approvalReady = Schema::hasTable('products') && Schema::hasColumn('products', 'approval_status');
        $perPage = self::normalizePerPage($request->query('products_per_page'));
        $sort = self::normalizeSort($request->query('products_sort'));
        $direction = self::normalizeDirection($request->query('products_direction'));

        $q = trim((string) $request->query('products_q', ''));
        $approval = (string) $request->query('products_approval', 'all');
        $active = (string) $request->query('products_active', 'all');
        $type = trim((string) $request->query('products_type', ''));
        $dateFrom = trim((string) $request->query('products_date_from', ''));
        $dateTo = trim((string) $request->query('products_date_to', ''));

        $allowedApprovals = ['all', 'pending', 'approved', 'rejected'];
        if (! in_array($approval, $allowedApprovals, true)) {
            $approval = 'all';
        }
        $allowedActive = ['all', 'active', 'inactive'];
        if (! in_array($active, $allowedActive, true)) {
            $active = 'all';
        }

        $typeOptions = [];
        foreach (Product::typeConfig() as $value => $cfg) {
            $typeOptions[] = ['value' => (string) $value, 'label' => (string) ($cfg['label'] ?? $value)];
        }
        $allowedTypes = array_column($typeOptions, 'value');
        if ($type !== '' && ! in_array($type, $allowedTypes, true)) {
            $type = '';
        }

        $empty = new LengthAwarePaginator([], 0, $perPage, max(1, (int) $request->query('products_page', 1)), [
            'path' => $request->url(),
            'pageName' => 'products_page',
            'query' => $request->query(),
        ]);

        if ($tenantId <= 0 || ! Schema::hasTable('products')) {
            return [
                'products' => $empty,
                'filters' => self::filtersPayload($q, $approval, $active, $type, $dateFrom, $dateTo, $perPage, $sort, $direction),
                'summary' => self::summary($tenantId),
                'approval_enabled' => $approvalReady,
                'type_options' => $typeOptions,
            ];
        }

        $query = Product::query()
            ->where('tenant_id', $tenantId)
            ->with(['reviewer:id,name']);

        if (Schema::hasTable('orders')) {
            $query->withCount([
                'orders as sales_count' => fn ($qq) => $qq->where('status', 'completed'),
            ])->withSum([
                'orders as sales_total' => fn ($qq) => $qq->where('status', 'completed'),
            ], 'amount');
        }

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($qq) use ($q, $like) {
                $qq->where('name', 'like', $like)
                    ->orWhere('checkout_slug', 'like', $like);
                if (ctype_digit($q)) {
                    $qq->orWhere('id', (int) $q);
                }
            });
        }

        if ($approvalReady && $approval !== 'all') {
            $query->where('approval_status', $approval);
        }

        if ($active === 'active') {
            $query->where('is_active', true);
        } elseif ($active === 'inactive') {
            $query->where('is_active', false);
        }

        if ($type !== '') {
            $query->where('type', $type);
        }

        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if (in_array($sort, ['sales_count', 'sales_total'], true) && Schema::hasTable('orders')) {
            $query->orderBy($sort, $direction);
        } elseif ($sort === 'sales_count' || $sort === 'sales_total') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderBy($sort, $direction);
        }

        if ($sort !== 'created_at') {
            $query->orderByDesc('id');
        }

        $deliverable = app(ProductDeliverablePreviewService::class);

        $paginator = $query
            ->paginate($perPage, ['*'], 'products_page')
            ->withQueryString()
            ->through(function (Product $p) use ($approvalReady, $deliverable) {
                $status = $approvalReady
                    ? ($p->approval_status ?? Product::APPROVAL_APPROVED)
                    : Product::APPROVAL_APPROVED;

                $imageUrl = null;
                if (! empty($p->image) && $p->tenant_id) {
                    try {
                        $imageUrl = (new StorageService((int) $p->tenant_id))->url($p->image);
                    } catch (\Throwable) {
                        $imageUrl = null;
                    }
                }

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'image_url' => $imageUrl,
                    'checkout_slug' => $p->checkout_slug,
                    'type' => $p->type,
                    'type_label' => $deliverable->typeLabel((string) $p->type),
                    'deliverable_preview' => $deliverable->forAdmin($p),
                    'price' => (float) $p->price,
                    'currency' => $p->currency ?? 'BRL',
                    'is_active' => (bool) $p->is_active,
                    'admin_blocked' => (bool) $p->admin_blocked,
                    'approval_status' => $status,
                    'approval_reason' => $approvalReady ? $p->approval_reason : null,
                    'approval_source' => $approvalReady ? $p->approval_source : null,
                    'reviewed_at' => $approvalReady ? $p->reviewed_at?->toIso8601String() : null,
                    'reviewed_by_name' => $p->reviewer?->name,
                    'publication_label' => self::publicationLabel($p, $status),
                    'sales_count' => (int) ($p->sales_count ?? 0),
                    'sales_total' => round((float) ($p->sales_total ?? 0), 2),
                    'created_at' => $p->created_at?->toIso8601String(),
                    'updated_at' => $p->updated_at?->toIso8601String(),
                    'can_approve' => $approvalReady && in_array($status, [Product::APPROVAL_PENDING, Product::APPROVAL_REJECTED], true),
                    'can_reject' => $approvalReady && in_array($status, [Product::APPROVAL_PENDING, Product::APPROVAL_APPROVED], true),
                    'can_activate' => $approvalReady && $status === Product::APPROVAL_APPROVED && ! $p->is_active,
                    'can_deactivate' => (bool) $p->is_active,
                ];
            });

        return [
            'products' => $paginator,
            'filters' => self::filtersPayload($q, $approval, $active, $type, $dateFrom, $dateTo, $perPage, $sort, $direction),
            'summary' => self::summary($tenantId),
            'approval_enabled' => $approvalReady,
            'type_options' => $typeOptions,
        ];
    }

    private static function publicationLabel(Product $p, string $approvalStatus): string
    {
        if ($p->admin_blocked) {
            return 'Bloqueado';
        }
        if ($approvalStatus === Product::APPROVAL_PENDING) {
            return 'Em análise';
        }
        if ($approvalStatus === Product::APPROVAL_REJECTED) {
            return 'Não aprovado';
        }
        if ($p->is_active) {
            return 'Ativo';
        }

        return 'Inativo';
    }

    /**
     * @return array<string, mixed>
     */
    private static function filtersPayload(
        string $q,
        string $approval,
        string $active,
        string $type,
        string $dateFrom,
        string $dateTo,
        int $perPage,
        string $sort,
        string $direction,
    ): array {
        return [
            'products_q' => $q !== '' ? $q : null,
            'products_approval' => $approval,
            'products_active' => $active,
            'products_type' => $type !== '' ? $type : null,
            'products_date_from' => $dateFrom !== '' ? $dateFrom : null,
            'products_date_to' => $dateTo !== '' ? $dateTo : null,
            'products_per_page' => $perPage,
            'products_sort' => $sort,
            'products_direction' => $direction,
        ];
    }
}
