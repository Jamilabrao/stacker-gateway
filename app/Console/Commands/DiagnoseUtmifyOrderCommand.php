<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\UtmifyTrackingDiagnostics;
use Illuminate\Console\Command;

class DiagnoseUtmifyOrderCommand extends Command
{
    protected $signature = 'utmify:diagnose-order
                            {order? : ID do pedido}
                            {--recent : Listar últimos 10 pedidos com diagnóstico resumido}';

    protected $description = 'Diagnóstico de rastreamento Utmify (UTMs, dispatches, integrações) por pedido.';

    public function handle(UtmifyTrackingDiagnostics $diagnostics): int
    {
        if ($this->option('recent')) {
            $orders = Order::query()
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            foreach ($orders as $order) {
                $data = $diagnostics->diagnose($order);
                $this->line(sprintf(
                    '#%s %s — waiting=%s paid=%s session=%s',
                    $order->id,
                    $order->status,
                    $data['utmify_waiting_sent_at'] ?? '—',
                    $data['utmify_paid_sent_at'] ?? '—',
                    ($data['session_resolved'] ?? false) ? 'ok' : 'missing'
                ));
            }
            $this->newLine();
            $this->line('Use: php artisan utmify:diagnose-order {id}');

            return self::SUCCESS;
        }

        $orderId = $this->argument('order');
        if ($orderId === null) {
            $this->error('Informe o ID do pedido ou use --recent');

            return self::FAILURE;
        }

        $order = Order::find($orderId);
        if (! $order) {
            $this->error("Pedido #{$orderId} não encontrado.");

            return self::FAILURE;
        }

        $this->line($diagnostics->formatReport($diagnostics->diagnose($order)));

        return self::SUCCESS;
    }
}
