<?php

namespace App\Http\Controllers;

use App\Services\AffiliateCommissionQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $stats = AffiliateCommissionQuery::statsFor($user->id, $request);
        $chart = AffiliateCommissionQuery::chartByDay($user->id, $request);
        $topProducts = AffiliateCommissionQuery::topProducts($user->id, $request, 5);

        $recent = AffiliateCommissionQuery::applyFilters(
            AffiliateCommissionQuery::baseQuery($user->id),
            $request
        )
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn ($c) => AffiliateCommissionQuery::commissionToArray($c))
            ->values()
            ->all();

        return Inertia::render('Produtos/Afiliados/Dashboard', [
            'period' => $request->query('period', 'mes'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'stats' => $stats,
            'grafico_comissao' => $chart,
            'top_produtos' => $topProducts,
            'vendas_recentes' => $recent,
        ]);
    }
}
