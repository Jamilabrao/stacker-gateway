<?php

namespace App\Http\Controllers;

use App\Services\CoproductionCommissionQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class CoproductionPanelController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $tenantId = CoproductionCommissionQuery::tenantIdForUser($user);

        $transactions = [
            'data' => [],
            'links' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 20,
                'total' => 0,
            ],
        ];

        if (Schema::hasTable('wallet_transactions')) {
            $paginator = CoproductionCommissionQuery::applyFilters(
                CoproductionCommissionQuery::baseQuery($tenantId),
                $request,
                $user
            )
                ->orderByDesc('wallet_transactions.id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn ($tx) => CoproductionCommissionQuery::transactionToArray($tx));

            $transactions = $paginator;
        }

        $participations = CoproductionCommissionQuery::participationsFor($user);
        $pendingCount = collect($participations)->where('status', 'pending')->count();
        $activeCount = collect($participations)->where('status', 'active')->count();

        return Inertia::render('Coproducao/Index', [
            'transactions' => $transactions,
            'stats' => CoproductionCommissionQuery::statsFor($user, $request),
            'participations' => $participations,
            'participation_counts' => [
                'pending' => $pendingCount,
                'active' => $activeCount,
                'total' => count($participations),
            ],
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'period' => (string) $request->query('period', 'total'),
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
                'product_id' => (string) $request->query('product_id', ''),
                'status' => (string) $request->query('status', 'all'),
            ],
            'products' => CoproductionCommissionQuery::productFilterOptions($user),
            'period_options' => [
                ['value' => 'hoje', 'label' => 'Hoje'],
                ['value' => 'ontem', 'label' => 'Ontem'],
                ['value' => '7dias', 'label' => '7 dias'],
                ['value' => 'mes', 'label' => 'Este mês'],
                ['value' => 'ano', 'label' => 'Este ano'],
                ['value' => 'total', 'label' => 'Todo o período'],
                ['value' => 'personalizado', 'label' => 'Personalizado'],
            ],
            'status_options' => [
                ['value' => 'all', 'label' => 'Todos os status'],
                ['value' => 'available', 'label' => 'Disponível'],
                ['value' => 'pending', 'label' => 'Em liquidação'],
            ],
        ]);
    }
}
