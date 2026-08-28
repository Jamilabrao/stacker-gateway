<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Payout\PlatformPayoutGateway;
use App\Services\Withdrawal\WithdrawalPolicyService;
use App\Services\WithdrawalPixReceiptService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class WithdrawalsController extends Controller
{
    private const WITHDRAWAL_STATUS_OPTIONS = ['all', 'pending', 'paid', 'rejected', 'failed'];

    public function __construct(
        protected WithdrawalPixReceiptService $receiptService,
    ) {}

    public function index(Request $request): Response
    {
        $withdrawalStatus = $request->query('withdrawal_status', 'all');
        if (! in_array($withdrawalStatus, self::WITHDRAWAL_STATUS_OPTIONS, true)) {
            $withdrawalStatus = 'all';
        }

        $origin = $request->query('origin', 'all');
        if (! in_array($origin, ['all', 'api'], true)) {
            $origin = 'all';
        }

        $withdrawalsPaginator = new LengthAwarePaginator([], 0, 40, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        if (Schema::hasTable('withdrawals')) {
            $wq = Withdrawal::query()
                ->with(['tenantOwner:id,name,email', 'apiApplication:id,name'])
                ->orderByDesc('created_at');
            if ($withdrawalStatus !== 'all') {
                if ($withdrawalStatus === 'pending') {
                    $wq->whereIn('status', ['pending', 'processing']);
                } else {
                    $wq->where('status', $withdrawalStatus);
                }
            }
            if ($origin === 'api') {
                $wq->whereNotNull('api_application_id');
            }
            $withdrawalsPaginator = $wq->paginate(40)->withQueryString()->through(
                fn (Withdrawal $w) => $this->receiptService->mapWithdrawalListItem($w)
            );
        }

        return Inertia::render('Platform/Withdrawals/Index', [
            'withdrawals' => $withdrawalsPaginator,
            'filters' => [
                'withdrawal_status' => $withdrawalStatus,
                'origin' => $origin,
            ],
            'payout_gateway_active' => PlatformPayoutGateway::activeSlug() ?? '',
            'require_manual_approval_pin' => $request->user() !== null
                && WithdrawalPolicyService::requiresOperationPinFor($request->user()),
            'has_manual_approval_pin' => WithdrawalPolicyService::hasManualApprovalPin(),
        ]);
    }
}
