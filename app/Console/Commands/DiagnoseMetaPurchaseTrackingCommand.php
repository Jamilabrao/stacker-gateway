<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\MetaPurchaseTrackingDiagnostics;
use Illuminate\Console\Command;

class DiagnoseMetaPurchaseTrackingCommand extends Command
{
    protected $signature = 'meta:diagnose-purchase
                            {order? : ID do pedido}
                            {--completed : Listar últimos 10 pedidos completed}
                            {--missing-capi : Listar completed sem meta_capi_sent_purchase}';

    protected $description = 'Diagnóstico de rastreamento Meta Purchase (CAPI + cookies) por pedido.';

    public function handle(MetaPurchaseTrackingDiagnostics $diagnostics): int
    {
        if ($this->option('missing-capi')) {
            $orders = Order::query()
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->filter(function (Order $o) {
                    $meta = is_array($o->metadata) ? $o->metadata : [];

                    return empty($meta['meta_capi_sent_purchase']);
                })
                ->take(10);

            if ($orders->isEmpty()) {
                $this->info('Nenhum pedido completed recente sem meta_capi_sent_purchase nos últimos 50.');

                return self::SUCCESS;
            }

            $this->warn('Pedidos completed sem CAPI confirmado:');
            foreach ($orders as $order) {
                $this->line("  #{$order->id} — {$order->payment_method} — R$ {$order->amount}");
            }
            $this->newLine();
            $this->line('Use: php artisan meta:diagnose-purchase {id}');

            return self::SUCCESS;
        }

        if ($this->option('completed')) {
            $orders = Order::query()
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            foreach ($orders as $order) {
                $this->line($diagnostics->formatReport($diagnostics->diagnose($order)));
                $this->line(str_repeat('-', 40));
            }

            return self::SUCCESS;
        }

        $orderId = $this->argument('order');
        if ($orderId === null) {
            $this->error('Informe o ID do pedido ou use --completed / --missing-capi');

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
