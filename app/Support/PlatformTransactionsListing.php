<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Filtros e paginação da listagem admin de transações (orders).
 */
final class PlatformTransactionsListing
{
    public const STATUS_OPTIONS = ['all', 'pending', 'completed', 'disputed', 'cancelled', 'refunded'];

    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    public static function normalizeStatus(?string $status): string
    {
        $status = is_string($status) ? trim($status) : 'all';
        if (! in_array($status, self::STATUS_OPTIONS, true)) {
            return 'all';
        }

        return $status;
    }

    public static function normalizePerPage(mixed $perPage): int
    {
        $perPage = (int) $perPage;
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            return self::DEFAULT_PER_PAGE;
        }

        return $perPage;
    }

    /**
     * @param  Builder<\App\Models\Order>  $query
     */
    public static function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status !== 'all') {
            $query->where('orders.status', $status);
        }
    }

    /**
     * Busca global server-side (não depende da página carregada).
     *
     * @param  Builder<\App\Models\Order>  $query
     */
    public static function applySearchFilter(Builder $query, string $q): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $likeLower = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';

        $query->where(function ($w) use ($q, $like, $likeLower, $digits) {
            $w->whereRaw('LOWER(orders.email) LIKE ?', [$likeLower])
                ->orWhereHas('user', function ($u) use ($likeLower) {
                    $u->whereRaw('LOWER(name) LIKE ?', [$likeLower])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$likeLower]);
                    if (Schema::hasColumn('users', 'document')) {
                        $u->orWhere('document', 'like', $likeLower);
                    }
                    if (Schema::hasColumn('users', 'phone')) {
                        $u->orWhere('phone', 'like', $likeLower);
                    }
                })
                ->orWhereHas('tenantOwner', function ($u) use ($likeLower) {
                    $u->whereRaw('LOWER(name) LIKE ?', [$likeLower])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$likeLower]);
                })
                ->orWhereHas('product', function ($p) use ($likeLower) {
                    $p->whereRaw('LOWER(name) LIKE ?', [$likeLower]);
                });

            if (Schema::hasColumn('orders', 'gateway_id')) {
                $w->orWhere('orders.gateway_id', 'like', $like);
            }

            if ($digits !== '') {
                if (Schema::hasColumn('orders', 'cpf')) {
                    $w->orWhere('orders.cpf', 'like', '%'.$digits.'%');
                }
                if (Schema::hasColumn('orders', 'phone')) {
                    $w->orWhere('orders.phone', 'like', '%'.$digits.'%');
                }
                if (Schema::hasColumn('users', 'document') || Schema::hasColumn('users', 'phone')) {
                    $w->orWhereHas('user', function ($u) use ($digits) {
                        $u->where(function ($inner) use ($digits) {
                            if (Schema::hasColumn('users', 'document')) {
                                $inner->orWhere('document', 'like', '%'.$digits.'%');
                            }
                            if (Schema::hasColumn('users', 'phone')) {
                                $inner->orWhere('phone', 'like', '%'.$digits.'%');
                            }
                        });
                    });
                }
            }

            if (ctype_digit($q)) {
                $w->orWhere('orders.id', (int) $q);
            }
        });
    }
}
