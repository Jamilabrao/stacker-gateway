<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Services\WithdrawalPixReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WithdrawalReceiptController extends Controller
{
    public function __construct(
        protected WithdrawalPixReceiptService $receiptService,
    ) {}

    public function seller(Request $request, Withdrawal $withdrawal): View|Response
    {
        $tenantId = (int) $request->user()->tenant_id;
        if ((int) $withdrawal->tenant_id !== $tenantId) {
            abort(403);
        }

        return $this->render($withdrawal, includePayerSection: false);
    }

    public function platform(Withdrawal $withdrawal): View|Response
    {
        return $this->render($withdrawal, includePayerSection: true);
    }

    private function render(Withdrawal $withdrawal, bool $includePayerSection): View|Response
    {
        if (! $this->receiptService->isAvailable($withdrawal)) {
            return response()->view('withdrawals.pix-receipt-unavailable', [
                'withdrawal_id' => $withdrawal->id,
                'status' => (string) $withdrawal->status,
            ], 404);
        }

        try {
            $data = $this->receiptService->viewData($withdrawal, $includePayerSection);

            return view('withdrawals.pix-receipt', $data);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('WithdrawalReceiptController: failed to render receipt', [
                'withdrawal_id' => $withdrawal->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            throw $e;
        }
    }
}
