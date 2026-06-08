<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\BuildsMerchantWalletProps;
use App\Http\Controllers\Controller;
use App\Models\TenantWallet;
use App\Models\User;
use App\Services\MerchantWalletAdminBlockService;
use App\Support\DemoMode;
use App\Support\Demo\DemoPlatformData;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class BalancesController extends Controller
{
    use BuildsMerchantWalletProps;

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $hasBalance = $request->query('has_balance', '1') !== '0';
        $perPage = 40;
        $page = max(1, (int) $request->query('page', 1));

        if (DemoMode::isEnabled()) {
            $payload = DemoPlatformData::balances(
                $search,
                $hasBalance,
                $request->url(),
                $request->query()
            );

            return Inertia::render('Platform/Balances/Index', $payload);
        }

        $usersQuery = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->orderBy('name');

        if ($search !== '') {
            $usersQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $users = $usersQuery->get();
        $tenantIds = $users->map(fn (User $u) => $this->tenantIdForUser($u))->unique()->filter(fn ($id) => $id > 0)->values();

        $wallets = Schema::hasTable('tenant_wallets')
            ? TenantWallet::query()->whereIn('tenant_id', $tenantIds)->get()->keyBy('tenant_id')
            : collect();

        $rows = $users->map(function (User $u) use ($wallets) {
            $tenantId = $this->tenantIdForUser($u);
            $w = $wallets->get($tenantId);
            $availableTotal = $w
                ? round(
                    (float) ($w->available_pix ?? 0)
                    + (float) ($w->available_card ?? 0)
                    + (float) ($w->available_boleto ?? 0),
                    2
                )
                : 0.0;
            $pendingTotal = $w
                ? round(
                    (float) ($w->pending_pix ?? 0)
                    + (float) ($w->pending_card ?? 0)
                    + (float) ($w->pending_boleto ?? 0),
                    2
                )
                : 0.0;

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'tenant_id' => $tenantId,
                'available_total' => $availableTotal,
                'pending_total' => $pendingTotal,
                'med_total' => $tenantId > 0 ? MerchantWalletAdminBlockService::totalMedHoldAmountForTenant($tenantId) : 0.0,
            ];
        });

        if ($hasBalance) {
            $rows = $rows->filter(function (array $row) {
                return abs($row['available_total']) > 0.0001
                    || abs($row['pending_total']) > 0.0001;
            });
        }

        $rows = $rows->sortByDesc('available_total')->values();
        $total = $rows->count();
        $pageItems = $rows->forPage($page, $perPage)->values()->all();

        $paginator = new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return Inertia::render('Platform/Balances/Index', [
            'merchants' => $paginator,
            'filters' => [
                'q' => $search,
                'has_balance' => $hasBalance,
            ],
        ]);
    }
}
