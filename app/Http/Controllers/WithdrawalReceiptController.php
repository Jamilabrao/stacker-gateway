<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Services\WithdrawalPixReceiptService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WithdrawalReceiptController extends Controller
{
    public function __construct(
        protected WithdrawalPixReceiptService $receiptService,
    ) {}

    public function seller(Request $request, Withdrawal $withdrawal): View
    {
        $tenantId = (int) $request->user()->tenant_id;
        if ((int) $withdrawal->tenant_id !== $tenantId) {
            abort(403);
        }

        return $this->render($withdrawal, includePayerSection: false);
    }

    public function platform(Withdrawal $withdrawal): View
    {
        return $this->render($withdrawal, includePayerSection: true);
    }

    private function render(Withdrawal $withdrawal, bool $includePayerSection): View
    {
        $data = $this->receiptService->viewData($withdrawal, $includePayerSection);

        return view('withdrawals.pix-receipt', $data);
    }
}
