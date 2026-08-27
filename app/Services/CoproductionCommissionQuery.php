<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CoproductionCommissionQuery
{
    public const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total', 'personalizado'];

    public static function tenantIdForUser(User $user): int
    {
        return (int) ($user->tenant_id ?? $user->id);
    }

    public static function baseQuery(int $tenantId): Builder
    {
        return WalletTransaction::query()
            ->where('wallet_transactions.tenant_id', $tenantId)
            ->whereIn('wallet_transactions.type', [
                WalletTransaction::TYPE_CREDIT_SALE,
                WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            ])
            ->where(function (Builder $q) {
                $q->where('wallet_transactions.meta->coproduction_role', 'coproducer')
                    ->orWhere('wallet_transactions.meta->coproduction', true)
                    ->orWhere('wallet_transactions.meta->coproduction', 1)
                    ->orWhere('wallet_transactions.meta->coproduction', 'true');
            })
            ->with([
                'order:id,tenant_id,product_id,status,payment_method,email,user_id,amount,public_reference,created_at',
                'order.user:id,name,email',
                'order.product:id,name,image,tenant_id,checkout_slug',
                'order.tenantOwner:id,name',
            ]);
    }

    public static function applyFilters(Builder $query, Request $request, User $user): Builder
    {
        $period = $request->query('period', 'total');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'total';
        }
        [$start, $end] = self::resolveDateRange($request, $period);

        if ($start && $end) {
            $query->whereBetween('wallet_transactions.created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('wallet_transactions.created_at', '>=', $start);
        } elseif ($end) {
            $query->where('wallet_transactions.created_at', '<=', $end);
        }

        $productId = trim((string) $request->query('product_id', ''));
        if ($productId !== '') {
            $allowedProductIds = self::productIdsForCoproducer($user);
            if (! in_array($productId, $allowedProductIds, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('order', fn (Builder $oq) => $oq->where('product_id', $productId));
            }
        }

        $status = trim((string) $request->query('status', 'all'));
        if ($status === 'available') {
            $query->where('wallet_transactions.type', WalletTransaction::TYPE_CREDIT_SALE);
        } elseif ($status === 'pending') {
            $query->where('wallet_transactions.type', WalletTransaction::TYPE_CREDIT_SALE_PENDING);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function (Builder $sub) use ($q) {
                $sub->where('wallet_transactions.order_id', 'like', '%'.$q.'%')
                    ->orWhereHas('order', function (Builder $oq) use ($q) {
                        $oq->where('public_reference', 'like', '%'.$q.'%')
                            ->orWhere('email', 'like', '%'.$q.'%')
                            ->orWhereHas('user', fn (Builder $uq) => $uq
                                ->where('name', 'like', '%'.$q.'%')
                                ->orWhere('email', 'like', '%'.$q.'%'))
                            ->orWhereHas('product', fn (Builder $pq) => $pq->where('name', 'like', '%'.$q.'%'));
                    });
            });
        }

        return $query;
    }

    /**
     * @return array{total_liquido: float, total_bruto: float, disponivel: float, pendente: float, total_transacoes: int}
     */
    public static function statsFor(User $user, Request $request): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return [
                'total_liquido' => 0.0,
                'total_bruto' => 0.0,
                'disponivel' => 0.0,
                'pendente' => 0.0,
                'total_transacoes' => 0,
            ];
        }

        $tenantId = self::tenantIdForUser($user);
        $base = self::applyFilters(self::baseQuery($tenantId), $request, $user);

        $rows = (clone $base)->get(['type', 'amount_gross', 'amount_net']);

        $disponivel = 0.0;
        $pendente = 0.0;
        $bruto = 0.0;
        $liquido = 0.0;

        foreach ($rows as $row) {
            $net = (float) $row->amount_net;
            $gross = (float) $row->amount_gross;
            $bruto += $gross;
            $liquido += $net;
            if ($row->type === WalletTransaction::TYPE_CREDIT_SALE) {
                $disponivel += $net;
            } else {
                $pendente += $net;
            }
        }

        return [
            'total_liquido' => round($liquido, 2),
            'total_bruto' => round($bruto, 2),
            'disponivel' => round($disponivel, 2),
            'pendente' => round($pendente, 2),
            'total_transacoes' => $rows->count(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function productIdsForCoproducer(User $user): array
    {
        if (! Schema::hasTable('product_coproducers')) {
            return [];
        }

        return ProductCoproducer::query()
            ->where('co_producer_user_id', $user->id)
            ->whereIn('status', [
                ProductCoproducer::STATUS_ACTIVE,
                ProductCoproducer::STATUS_REVOKED,
                ProductCoproducer::STATUS_EXPIRED,
            ])
            ->pluck('product_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public static function productFilterOptions(User $user): array
    {
        $ids = self::productIdsForCoproducer($user);
        if ($ids === [] || ! Schema::hasTable('products')) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $p) => [
                'id' => (string) $p->id,
                'name' => (string) $p->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function participationsFor(User $user): array
    {
        if (! Schema::hasTable('product_coproducers')) {
            return [];
        }

        $email = ProductCoproducer::normalizeEmail((string) $user->email);
        $storage = app(StorageService::class);

        $rows = ProductCoproducer::query()
            ->where(function (Builder $q) use ($user, $email) {
                $q->where('co_producer_user_id', $user->id)
                    ->orWhere(function (Builder $pending) use ($email) {
                        $pending->where('status', ProductCoproducer::STATUS_PENDING)
                            ->whereRaw('LOWER(email) = ?', [$email]);
                    });
            })
            ->with(['product', 'inviter:id,name,email'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")
            ->orderByDesc('accepted_at')
            ->orderByDesc('updated_at')
            ->get();

        return $rows->map(function (ProductCoproducer $row) use ($storage) {
            $product = $row->product;
            $owner = $product && $product->tenant_id
                ? User::query()->find($product->tenant_id)
                : null;
            $imageUrl = $product && $product->image
                ? $storage->url($product->image)
                : null;

            return [
                'id' => $row->id,
                'token' => $row->token,
                'status' => $row->status,
                'commission_percent' => (float) $row->commission_percent,
                'commission_on_direct_sales' => (bool) $row->commission_on_direct_sales,
                'commission_on_affiliate_sales' => (bool) $row->commission_on_affiliate_sales,
                'duration_preset' => $row->duration_preset,
                'starts_at' => $row->starts_at?->toIso8601String(),
                'ends_at' => $row->ends_at?->toIso8601String(),
                'accepted_at' => $row->accepted_at?->toIso8601String(),
                'inviter_name' => $row->inviter?->name ?? '',
                'product' => [
                    'id' => $product?->id,
                    'name' => $product?->name ?? '—',
                    'image_url' => $imageUrl,
                    'checkout_slug' => $product?->checkout_slug,
                    'owner_name' => $owner?->name ?? '',
                ],
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function transactionToArray(WalletTransaction $tx): array
    {
        $meta = is_array($tx->meta) ? $tx->meta : [];
        $order = $tx->order;
        $product = $order?->product;
        $labels = WalletTransaction::typeLabels();

        $clearsAt = isset($meta['clears_at']) && is_string($meta['clears_at'])
            ? $meta['clears_at']
            : null;
        $released = ! empty($meta['released_at']);

        $status = $tx->type === WalletTransaction::TYPE_CREDIT_SALE
            ? 'available'
            : ($released ? 'available' : 'pending');

        $statusLabel = match ($status) {
            'available' => 'Disponível',
            default => 'Em liquidação',
        };

        return [
            'id' => $tx->id,
            'order_id' => $tx->order_id,
            'public_reference' => $order?->public_reference,
            'type' => $tx->type,
            'type_label' => $labels[$tx->type] ?? $tx->type,
            'status' => $status,
            'status_label' => $statusLabel,
            'bucket' => $tx->bucket,
            'amount_gross' => (float) $tx->amount_gross,
            'amount_fee' => (float) $tx->amount_fee,
            'amount_net' => (float) $tx->amount_net,
            'payment_method' => $order?->payment_method ?? ($meta['payment_method'] ?? null),
            'product_id' => $product?->id ?? $order?->product_id,
            'product_name' => $product?->name ?? '—',
            'producer_name' => $order?->tenantOwner?->name ?? '—',
            'customer_name' => $order?->user?->name,
            'customer_email' => $order?->email ?? $order?->user?->email,
            'product_coproducer_id' => $meta['product_coproducer_id'] ?? null,
            'clears_at' => $clearsAt,
            'created_at' => $tx->created_at?->toIso8601String(),
            'order_amount' => $order ? (float) $order->amount : null,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function resolveDateRange(Request $request, string $period): array
    {
        $now = Carbon::now();

        if ($period === 'personalizado') {
            $from = $request->query('date_from');
            $to = $request->query('date_to');
            $start = is_string($from) && $from !== '' ? Carbon::parse($from)->startOfDay() : null;
            $end = is_string($to) && $to !== '' ? Carbon::parse($to)->endOfDay() : null;

            return [$start?->toDateTimeString(), $end?->toDateTimeString()];
        }

        return match ($period) {
            'hoje' => [$now->copy()->startOfDay()->toDateTimeString(), $now->copy()->endOfDay()->toDateTimeString()],
            'ontem' => [
                $now->copy()->subDay()->startOfDay()->toDateTimeString(),
                $now->copy()->subDay()->endOfDay()->toDateTimeString(),
            ],
            '7dias' => [$now->copy()->subDays(6)->startOfDay()->toDateTimeString(), $now->copy()->endOfDay()->toDateTimeString()],
            'mes' => [$now->copy()->startOfMonth()->toDateTimeString(), $now->copy()->endOfMonth()->toDateTimeString()],
            'ano' => [$now->copy()->startOfYear()->toDateTimeString(), $now->copy()->endOfYear()->toDateTimeString()],
            default => [null, null],
        };
    }
}
