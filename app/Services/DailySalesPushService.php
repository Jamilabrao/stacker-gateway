<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PanelPushDailySummaryLog;
use App\Models\User;
use App\Support\DailySalesPushSettings;
use App\Support\UserPushPreferences;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DailySalesPushService
{
    public function __construct(
        protected PanelPushService $panelPushService,
    ) {}

    /**
     * Processa o resumo do dia operacional (ontem relativo ao horário de corte, ou data explícita).
     */
    public function processReferenceDate(?Carbon $referenceDate = null): int
    {
        if (! DailySalesPushSettings::enabled()) {
            return 0;
        }
        if (! Schema::hasTable('orders') || ! Schema::hasTable('panel_push_daily_summary_logs')) {
            return 0;
        }

        $tz = DailySalesPushSettings::timezone();
        $day = $referenceDate
            ? $referenceDate->copy()->timezone($tz)->startOfDay()
            : Carbon::now($tz)->subDay()->startOfDay();

        $startUtc = $day->copy()->timezone('UTC');
        $endUtc = $day->copy()->endOfDay()->timezone('UTC');
        $dateKey = $day->toDateString();

        $rows = Order::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$startUtc, $endUtc])
            ->select([
                'tenant_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('COALESCE(SUM(amount), 0) as orders_total'),
            ])
            ->groupBy('tenant_id')
            ->get();

        $byTenantMethods = Order::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$startUtc, $endUtc])
            ->get(['tenant_id', 'payment_method', 'metadata', 'amount', 'gateway']);

        $methodsMap = [];
        foreach ($byTenantMethods as $order) {
            /** @var Order $order */
            $tid = (int) $order->tenant_id;
            $key = $order->paymentMethodReportKey();
            $label = Order::paymentMethodReportLabel($key);
            $methodsMap[$tid][$label] = ($methodsMap[$tid][$label] ?? 0) + 1;
        }

        $sent = 0;
        $onlyWhenHasSales = DailySalesPushSettings::onlyWhenHasSales();

        if ($onlyWhenHasSales) {
            foreach ($rows as $row) {
                $sent += $this->sendForTenant(
                    (int) $row->tenant_id,
                    $dateKey,
                    (int) $row->orders_count,
                    (float) $row->orders_total,
                    $methodsMap[(int) $row->tenant_id] ?? []
                ) ? 1 : 0;
            }

            return $sent;
        }

        $merchantIds = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where('account_status', 'approved')
            ->pluck('id');

        $statsByTenant = $rows->keyBy('tenant_id');
        foreach ($merchantIds as $tenantId) {
            $row = $statsByTenant->get($tenantId);
            $count = (int) ($row->orders_count ?? 0);
            $total = (float) ($row->orders_total ?? 0);
            $sent += $this->sendForTenant(
                (int) $tenantId,
                $dateKey,
                $count,
                $total,
                $methodsMap[(int) $tenantId] ?? []
            ) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * @param  array<string, int>  $byMethod
     */
    private function sendForTenant(int $tenantId, string $dateKey, int $count, float $total, array $byMethod): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        if (! UserPushPreferences::allowsEvent($tenantId, 'daily_sales_summary')) {
            return false;
        }

        try {
            PanelPushDailySummaryLog::query()->create([
                'tenant_id' => $tenantId,
                'reference_date' => $dateKey,
                'orders_count' => $count,
                'orders_total' => $total,
                'by_method' => $byMethod,
                'status' => 'sending',
            ]);
        } catch (\Throwable $e) {
            // unique violation = já enviado
            Log::info('DailySalesPushService: já processado', [
                'tenant_id' => $tenantId,
                'date' => $dateKey,
            ]);

            return false;
        }

        if ($count <= 0) {
            $title = 'Resumo de vendas do dia';
            $body = 'Hoje não foram registradas vendas aprovadas na sua operação.';
        } else {
            $formatted = number_format($total, 2, ',', '.');
            $title = 'Resumo de vendas do dia';
            $body = "Você realizou {$count} ".($count === 1 ? 'venda' : 'vendas')." hoje, totalizando R$ {$formatted}.";
            if ($byMethod !== []) {
                $parts = [];
                foreach ($byMethod as $label => $qty) {
                    $parts[] = "{$qty} via {$label}";
                }
                $body .= "\n".implode(' · ', $parts);
            }
        }

        $delivered = $this->panelPushService->sendAndPersistToTenant(
            $tenantId,
            'daily_sales_summary',
            $title,
            $body,
            '/vendas',
            'daily_sales_'.$tenantId.'_'.$dateKey
        );

        PanelPushDailySummaryLog::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('reference_date', $dateKey)
            ->update(['status' => $delivered > 0 ? 'sent' : 'no_subscription']);

        return true;
    }
}
