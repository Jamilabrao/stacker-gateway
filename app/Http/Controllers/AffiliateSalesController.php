<?php

namespace App\Http\Controllers;

use App\Models\AffiliateCommission;
use App\Services\AffiliateCommissionQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateSalesController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = AffiliateCommissionQuery::applyFilters(
            AffiliateCommissionQuery::baseQuery($user->id),
            $request
        )->orderByDesc('created_at');

        $paginator = $query
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($c) => AffiliateCommissionQuery::commissionToArray($c));

        return Inertia::render('Produtos/Afiliados/Vendas', [
            'vendas' => $paginator,
            'stats' => AffiliateCommissionQuery::statsFor($user->id, $request),
            'filters' => [
                'q' => $request->query('q', ''),
                'period' => $request->query('period', 'total'),
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
                'product_id' => $request->query('product_id', ''),
                'producer_id' => $request->query('producer_id', ''),
                'status' => $request->query('status', 'all'),
                'payment_method' => $request->query('payment_method', 'all'),
            ],
            'products' => AffiliateCommissionQuery::productFilterOptions($user->id),
            'producers' => AffiliateCommissionQuery::producerFilterOptions($user->id),
            'status_options' => [
                ['value' => 'all', 'label' => 'Todos'],
                ['value' => AffiliateCommission::STATUS_PENDING, 'label' => 'Pendente'],
                ['value' => AffiliateCommission::STATUS_APPROVED, 'label' => 'Aprovada'],
                ['value' => AffiliateCommission::STATUS_CANCELLED, 'label' => 'Cancelada'],
                ['value' => AffiliateCommission::STATUS_REFUNDED, 'label' => 'Estornada'],
            ],
        ]);
    }
}
