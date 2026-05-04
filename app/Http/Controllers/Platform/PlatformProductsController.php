<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class PlatformProductsController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $filter = $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'blocked', 'purchaseable'], true)) {
            $filter = 'all';
        }

        $paginator = new LengthAwarePaginator([], 0, 30, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'admin_blocked')) {
            $query = Product::query()
                ->with(['tenantOwner:id,name,email'])
                ->orderByDesc('created_at');

            if ($q !== '') {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
                $query->where(function ($qq) use ($like) {
                    $qq->where('name', 'like', $like)->orWhere('checkout_slug', 'like', $like);
                });
            }
            if ($filter === 'blocked') {
                $query->where('admin_blocked', true);
            } elseif ($filter === 'purchaseable') {
                $query->availableForPurchase();
            }

            $paginator = $query->paginate(30)->withQueryString()->through(function (Product $p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'checkout_slug' => $p->checkout_slug,
                    'type' => $p->type,
                    'price' => (float) $p->price,
                    'currency' => $p->currency ?? 'BRL',
                    'is_active' => (bool) $p->is_active,
                    'admin_blocked' => (bool) $p->admin_blocked,
                    'tenant_id' => $p->tenant_id,
                    'infoprodutor_name' => $p->tenantOwner?->name ?? '—',
                    'infoprodutor_email' => $p->tenantOwner?->email,
                    'created_at' => $p->created_at?->toIso8601String(),
                ];
            });
        }

        return Inertia::render('Platform/Products/Index', [
            'products' => $paginator,
            'filters' => [
                'q' => $q !== '' ? $q : null,
                'filter' => $filter,
            ],
        ]);
    }

    public function updateBlock(Request $request, Product $product): RedirectResponse
    {
        if (! Schema::hasColumn('products', 'admin_blocked')) {
            return redirect()->back()->with('error', 'Execute as migrações do banco para usar o bloqueio de produtos.');
        }

        $validated = $request->validate([
            'admin_blocked' => ['required', 'boolean'],
        ]);

        $was = (bool) $product->admin_blocked;
        $product->update(['admin_blocked' => $validated['admin_blocked']]);

        PlatformAuditService::log('platform.product.admin_block_updated', [
            'product_id' => $product->id,
            'admin_blocked' => (bool) $product->admin_blocked,
            'previous' => $was,
        ], $request);

        $msg = $product->admin_blocked
            ? 'Produto bloqueado: checkout e vendas via API ficam indisponíveis para ele.'
            : 'Bloqueio removido. Se o produto estiver ativo, volta a poder ser vendido.';

        return redirect()->back()->with('success', $msg);
    }
}
