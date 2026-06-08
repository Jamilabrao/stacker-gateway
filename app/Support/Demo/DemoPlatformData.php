<?php

namespace App\Support\Demo;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class DemoPlatformData
{
    private const PRODUCT_NAMES = [
        'Curso Marketing Digital',
        'Mentoria Premium',
        'E-book Vendas Online',
        'Comunidade VIP',
        'Template Notion Pro',
        'Workshop Instagram',
    ];

    private const GATEWAYS = ['cajupay', 'spacepag', 'efi', 'mercadopago'];

    private const STATUSES = ['completed', 'completed', 'completed', 'pending', 'completed', 'cancelled', 'refunded'];

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(string $period): array
    {
        $seed = self::periodSeed($period);
        $multiplier = self::periodMultiplier($period);

        $quantidadeVendas = (int) round(180 * $multiplier + ($seed % 40));
        $vendasTotais = round($quantidadeVendas * (97.5 + ($seed % 50) / 10), 2);
        $ticketMedio = $quantidadeVendas > 0 ? round($vendasTotais / $quantidadeVendas, 2) : 0.0;

        $taxasCobradas = round($vendasTotais * 0.034, 2);
        $custoAdqVendas = round($vendasTotais * 0.012, 2);
        $custoAdqSaques = round(850 + ($seed % 200), 2);
        $faturamentoLiquido = round($taxasCobradas - $custoAdqVendas - $custoAdqSaques, 2);

        return [
            'period' => $period,
            'kpis' => [
                'wallet_available' => round(84250.75 + ($seed % 5000), 2),
                'wallet_pending' => round(12340.20 + ($seed % 2000), 2),
                'vendas_totais' => $vendasTotais,
                'quantidade_vendas' => $quantidadeVendas,
                'ticket_medio' => $ticketMedio,
                'withdrawals_total' => round(45200.00 * min($multiplier, 1.5), 2),
                'withdrawals_pending' => round(3200.00 + ($seed % 800), 2),
                'infoprodutores_count' => 128 + ($seed % 15),
                'faturamento_taxas_cobradas' => $taxasCobradas,
                'faturamento_custo_adquirente_vendas' => $custoAdqVendas,
                'faturamento_custo_adquirente_saques' => $custoAdqSaques,
                'faturamento_liquido' => $faturamentoLiquido,
            ],
            'grafico_vendas' => self::chart($period, $seed, $vendasTotais),
            'ultimas_transacoes' => self::recentTransactions(10, $seed),
        ];
    }

    /**
     * @return array{orders: LengthAwarePaginator, filters: array{status: string, q: string}}
     */
    public static function transactions(string $status, string $q, string $path, array $query): array
    {
        $seed = crc32($status.'|'.$q);
        $items = [];

        for ($i = 0; $i < 15; $i++) {
            $idx = ($seed + $i) % count(self::PRODUCT_NAMES);
            $orderStatus = self::STATUSES[($seed + $i) % count(self::STATUSES)];
            if ($status !== 'all' && $orderStatus !== $status) {
                $orderStatus = $status === 'disputed' ? 'completed' : $status;
            }

            $amount = round(47.0 + (($seed + $i * 17) % 450), 2);
            $fee = round($amount * 0.039, 2);
            $created = Carbon::now()->subHours($i * 3 + 2)->toIso8601String();

            $items[] = [
                'id' => 900000 + $seed + $i,
                'email' => 'cliente'.($i + 1).'@demo.exemplo',
                'status' => $orderStatus,
                'amount' => $amount,
                'amount_total' => $amount,
                'amount_gross' => $amount,
                'amount_fee' => $fee,
                'amount_net' => round($amount - $fee, 2),
                'gateway' => self::GATEWAYS[($seed + $i) % count(self::GATEWAYS)],
                'gateway_label' => 'PIX',
                'payment_method_label' => 'PIX',
                'product_display_name' => self::PRODUCT_NAMES[$idx],
                'product_label' => self::PRODUCT_NAMES[$idx],
                'product_name' => self::PRODUCT_NAMES[$idx],
                'payment_type_label' => ($i % 4 === 0) ? 'Pagamento recorrente' : 'Pagamento único',
                'customer_name' => 'Cliente Demo '.($i + 1),
                'customer_email' => 'cliente'.($i + 1).'@demo.exemplo',
                'infoprodutor_name' => 'Vendedor Demo',
                'infoprodutor_email' => 'demo-vendedor@demo.local',
                'checkout_url' => url('/c/demo-produto'),
                'has_open_med_dispute' => $orderStatus === 'completed' && $i === 2,
                'created_at' => $created,
            ];
        }

        if ($q !== '') {
            $items = array_values(array_filter($items, function (array $row) use ($q) {
                return str_contains(strtolower((string) $row['email']), strtolower($q))
                    || str_contains(strtolower((string) $row['product_name']), strtolower($q));
            }));
        }

        $paginator = new LengthAwarePaginator(
            $items,
            count($items),
            40,
            1,
            ['path' => $path, 'query' => $query]
        );

        return [
            'orders' => $paginator,
            'filters' => [
                'status' => $status,
                'q' => $q,
            ],
        ];
    }

    /**
     * @return array{merchants: LengthAwarePaginator, filters: array{q: string, has_balance: bool}}
     */
    public static function balances(string $search, bool $hasBalance, string $path, array $query): array
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $available = round(500 + ($i * 1379.45) % 25000, 2);
            $pending = round(100 + ($i * 421.11) % 8000, 2);
            $rows[] = [
                'id' => 1000 + $i,
                'name' => 'Infoprodutor Demo '.($i + 1),
                'email' => 'vendedor'.($i + 1).'@demo.exemplo',
                'tenant_id' => 2000 + $i,
                'available_total' => $available,
                'pending_total' => $pending,
                'med_total' => $i === 1 ? 450.0 : 0.0,
            ];
        }

        if ($search !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($search) {
                return str_contains(strtolower($row['name']), strtolower($search))
                    || str_contains(strtolower($row['email']), strtolower($search));
            }));
        }

        if ($hasBalance) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['available_total'] > 0 || $row['pending_total'] > 0));
        }

        $paginator = new LengthAwarePaginator(
            $rows,
            count($rows),
            40,
            1,
            ['path' => $path, 'query' => $query]
        );

        return [
            'merchants' => $paginator,
            'filters' => [
                'q' => $search,
                'has_balance' => $hasBalance,
            ],
        ];
    }

    /**
     * @return array<int, array{data: string, total: float}>
     */
    private static function chart(string $period, int $seed, float $total): array
    {
        $isHourly = in_array($period, ['hoje', 'ontem'], true);

        if ($isHourly) {
            $result = [];
            $weights = [];
            $sum = 0;
            for ($h = 0; $h <= 23; $h++) {
                $w = 1 + (($seed + $h * 7) % 9);
                $weights[$h] = $w;
                $sum += $w;
            }
            foreach ($weights as $h => $w) {
                $result[] = [
                    'data' => (string) $h,
                    'total' => round($total * ($w / max($sum, 1)), 2),
                ];
            }

            return $result;
        }

        $days = match ($period) {
            '7dias' => 7,
            'mes' => 28,
            'ano' => 12,
            'total' => 12,
            default => 7,
        };

        $result = [];
        $weights = [];
        $sum = 0;
        for ($d = 0; $d < $days; $d++) {
            $w = 1 + (($seed + $d * 11) % 12);
            $weights[] = $w;
            $sum += $w;
        }

        foreach ($weights as $d => $w) {
            $label = in_array($period, ['ano', 'total'], true)
                ? Carbon::now()->startOfYear()->addMonths($d)->format('Y-m')
                : Carbon::now()->subDays($days - $d - 1)->format('Y-m-d');

            $result[] = [
                'data' => $label,
                'total' => round($total * ($w / max($sum, 1)), 2),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function recentTransactions(int $count, int $seed): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $idx = ($seed + $i) % count(self::PRODUCT_NAMES);
            $rows[] = [
                'id' => 800000 + $seed + $i,
                'email' => 'comprador'.($i + 1).'@demo.exemplo',
                'product_name' => self::PRODUCT_NAMES[$idx],
                'amount' => round(57 + (($seed + $i * 13) % 320), 2),
                'status' => 'completed',
                'gateway' => self::GATEWAYS[($seed + $i) % count(self::GATEWAYS)],
                'created_at' => Carbon::now()->subMinutes($i * 18 + 5)->toIso8601String(),
            ];
        }

        return $rows;
    }

    private static function periodSeed(string $period): int
    {
        return abs(crc32($period));
    }

    private static function periodMultiplier(string $period): float
    {
        return match ($period) {
            'hoje' => 0.08,
            'ontem' => 0.07,
            '7dias' => 0.45,
            'mes' => 1.0,
            'ano' => 8.5,
            'total' => 14.0,
            default => 1.0,
        };
    }
}
