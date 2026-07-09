<?php

namespace App\Http\Controllers;

use App\Services\AffiliateCommissionQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateReportsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $period = $request->query('period', 'mes');
        if (! in_array($period, AffiliateCommissionQuery::PERIODS, true)) {
            $period = 'mes';
        }

        return Inertia::render('Produtos/Afiliados/Relatorios', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'stats' => AffiliateCommissionQuery::statsFor($user->id, $request),
            'grafico_comissao' => AffiliateCommissionQuery::chartByDay($user->id, $request),
            'comissao_por_produto' => AffiliateCommissionQuery::topProducts($user->id, $request, 10),
            'filters' => [
                'product_id' => $request->query('product_id', ''),
                'producer_id' => $request->query('producer_id', ''),
                'status' => $request->query('status', 'all'),
                'payment_method' => $request->query('payment_method', 'all'),
            ],
            'products' => AffiliateCommissionQuery::productFilterOptions($user->id),
            'producers' => AffiliateCommissionQuery::producerFilterOptions($user->id),
        ]);
    }
}
