<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CajuPay\CajuPayPixRefundConfirmationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileCajuPayRefundsCommand extends Command
{
    protected $signature = 'payments:reconcile-cajupay-refunds
                            {--limit=100 : Máximo de pedidos refund_pending por execução}';

    protected $description = 'Confirma na CajuPay reembolsos em andamento (pagamento cancelado/reembolsado) e efetiva no Stacker.';

    public function handle(CajuPayPixRefundConfirmationService $confirmation): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $orders = Order::query()
            ->where('status', 'refund_pending')
            ->where('gateway', 'cajupay')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $confirmed = 0;

        foreach ($orders as $order) {
            $checked++;
            try {
                if ($confirmation->confirmIfRemoteCancelled($order)) {
                    $confirmed++;
                }
            } catch (\Throwable $e) {
                Log::warning('payments:reconcile-cajupay-refunds', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Checados: {$checked} | Efetivados: {$confirmed}");

        return self::SUCCESS;
    }
}
