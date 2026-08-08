<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\MemberAreaResolver;
use App\Services\PlatformAdminDeletionService;
use App\Services\PlatformAuditService;
use App\Services\ProductApprovalService;
use App\Services\ProductDeliverablePreviewService;
use App\Support\MemberAreaAdminPreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PlatformProductsController extends Controller
{
    public function __construct(
        protected ProductDeliverablePreviewService $deliverablePreview,
        protected ProductApprovalService $approvalService,
    ) {}

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $filter = $request->query('filter', 'all');
        $allowedFilters = ['all', 'blocked', 'purchaseable', 'pending', 'approved', 'rejected', 'active', 'inactive'];
        if (! in_array($filter, $allowedFilters, true)) {
            $filter = 'all';
        }

        $paginator = new LengthAwarePaginator([], 0, 30, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        $approvalReady = Schema::hasTable('products') && Schema::hasColumn('products', 'approval_status');

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'admin_blocked')) {
            $query = Product::query()
                ->with(['tenantOwner:id,name,email', 'memberAreaDomain'])
                ->orderByDesc('created_at');

            if ($q !== '') {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
                $query->where(function ($qq) use ($like) {
                    $qq->where('name', 'like', $like)
                        ->orWhere('checkout_slug', 'like', $like)
                        ->orWhereHas('tenantOwner', function ($owner) use ($like) {
                            $owner->where('name', 'like', $like)->orWhere('email', 'like', $like);
                        });
                });
            }

            match ($filter) {
                'blocked' => $query->where('admin_blocked', true),
                'purchaseable' => $query->availableForPurchase(),
                'pending' => $approvalReady ? $query->where('approval_status', Product::APPROVAL_PENDING) : null,
                'approved' => $approvalReady ? $query->where('approval_status', Product::APPROVAL_APPROVED) : null,
                'rejected' => $approvalReady ? $query->where('approval_status', Product::APPROVAL_REJECTED) : null,
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                default => null,
            };

            $paginator = $query->paginate(30)->withQueryString()->through(function (Product $p) use ($approvalReady) {
                $status = $approvalReady
                    ? ($p->approval_status ?? Product::APPROVAL_APPROVED)
                    : Product::APPROVAL_APPROVED;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'checkout_slug' => $p->checkout_slug,
                    'type' => $p->type,
                    'type_label' => $this->deliverablePreview->typeLabel((string) $p->type),
                    'deliverable_preview' => $this->deliverablePreview->forAdmin($p),
                    'price' => (float) $p->price,
                    'currency' => $p->currency ?? 'BRL',
                    'is_active' => (bool) $p->is_active,
                    'admin_blocked' => (bool) $p->admin_blocked,
                    'approval_status' => $status,
                    'approval_reason' => $approvalReady ? $p->approval_reason : null,
                    'reviewed_at' => $approvalReady ? $p->reviewed_at?->toIso8601String() : null,
                    'tenant_id' => $p->tenant_id,
                    'infoprodutor_name' => $p->tenantOwner?->name ?? '—',
                    'infoprodutor_email' => $p->tenantOwner?->email,
                    'created_at' => $p->created_at?->toIso8601String(),
                    'can_approve' => $approvalReady && in_array($status, [Product::APPROVAL_PENDING, Product::APPROVAL_REJECTED], true),
                    'can_reject' => $approvalReady && in_array($status, [Product::APPROVAL_PENDING, Product::APPROVAL_APPROVED], true),
                    'can_activate' => $approvalReady && $status === Product::APPROVAL_APPROVED && ! $p->is_active,
                    'can_deactivate' => (bool) $p->is_active,
                ];
            });
        }

        return Inertia::render('Platform/Products/Index', [
            'products' => $paginator,
            'filters' => [
                'q' => $q !== '' ? $q : null,
                'filter' => $filter,
            ],
            'approval_enabled' => $approvalReady,
        ]);
    }

    public function approve(Request $request, Product $product): RedirectResponse
    {
        try {
            $this->approvalService->approve($product, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Produto aprovado e checkout liberado. O produto foi ativado automaticamente (se não estiver bloqueado).');
    }

    public function reject(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.ProductApprovalService::REASON_MIN, 'max:'.ProductApprovalService::REASON_MAX],
        ], [
            'reason.required' => 'Informe o motivo da não aprovação.',
            'reason.min' => 'O motivo deve ter pelo menos :min caracteres.',
            'reason.max' => 'O motivo deve ter no máximo :max caracteres.',
        ]);

        try {
            $this->approvalService->reject($product, $request->user(), $validated['reason'], $request);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'O produto não foi aprovado. O motivo foi enviado ao infoprodutor.');
    }

    public function updateActive(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'is_active' => ['required'],
        ]);

        try {
            $this->approvalService->setActiveByAdmin(
                $product,
                $request->boolean('is_active'),
                $request->user(),
                $request
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with(
            'success',
            $request->boolean('is_active')
                ? 'Produto ativado para venda (aprovado e ativo).'
                : 'Produto desativado. Ele deixa de aparecer como vendável.'
        );
    }

    /**
     * Abre a Área de Membros em modo de visualização administrativa (sem compra/matrícula).
     */
    public function previewMemberArea(Request $request, Product $product, MemberAreaResolver $resolver): RedirectResponse
    {
        if ($product->type !== Product::TYPE_AREA_MEMBROS) {
            return redirect()
                ->route('plataforma.produtos.index')
                ->with('error', 'Este produto não possui uma Área de Membros configurada.');
        }

        if (! MemberAreaAdminPreview::canPreviewProduct($request->user(), $product)) {
            abort(403, 'Você não possui permissão para visualizar esta Área de Membros.');
        }

        try {
            $url = $resolver->baseUrlForProduct($product);
        } catch (\Throwable) {
            $url = null;
        }

        if (! is_string($url) || $url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return redirect()
                ->route('plataforma.produtos.index')
                ->with('error', 'Este produto não possui uma Área de Membros configurada.');
        }

        MemberAreaAdminPreview::markSession($request, $product);

        $reason = trim((string) $request->query('reason', $request->input('reason', '')));
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        PlatformAuditService::log('platform.product.member_area_previewed', [
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
            'product_name' => $product->name,
            'reason' => $reason !== '' ? $reason : null,
        ], $request);

        return redirect()->away($url);
    }

    public function updateBlock(Request $request, Product $product): RedirectResponse
    {
        if (! Schema::hasColumn('products', 'admin_blocked')) {
            return redirect()->back()->with('error', 'Execute as migrações do banco para usar o bloqueio de produtos.');
        }

        $request->validate([
            'admin_blocked' => ['required'],
        ]);

        $blocked = $request->boolean('admin_blocked');

        $was = (bool) $product->admin_blocked;
        $product->admin_blocked = $blocked;
        $product->save();
        $product->refresh();

        PlatformAuditService::log('platform.product.admin_block_updated', [
            'product_id' => $product->id,
            'admin_blocked' => (bool) $product->admin_blocked,
            'previous' => $was,
        ], $request);

        $msg = $product->admin_blocked
            ? 'Produto bloqueado: checkout e vendas via API ficam indisponíveis para ele.'
            : 'Bloqueio removido. Se o produto estiver aprovado e ativo, volta a poder ser vendido.';

        return redirect()->back()->with('success', $msg);
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $productId = $product->id;
        $productName = $product->name;
        $tenantId = $product->tenant_id;

        try {
            PlatformAdminDeletionService::deleteProduct($product);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Não foi possível excluir o produto: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.product.deleted', [
            'product_id' => $productId,
            'product_name' => $productName,
            'tenant_id' => $tenantId,
        ], $request);

        return redirect()->route('plataforma.produtos.index')->with('success', 'Produto "'.$productName.'" excluído permanentemente.');
    }
}
