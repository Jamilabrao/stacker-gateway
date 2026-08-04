<?php

namespace App\Services\MetricsTracking;

use App\Models\MetricsEvent;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MetricsExportService
{
    public function __construct(
        private readonly MetricsAnalyticsService $analytics,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function download(
        string $format,
        string $type,
        ?int $tenantId,
        $start,
        $end,
        array $filters,
        string $dimension = 'utm_source',
        bool $platformScope = false,
        ?string $filenamePrefix = null,
    ): StreamedResponse {
        $format = strtolower($format) === 'xlsx' ? 'xlsx' : 'csv';
        $type = $type === 'clicks' ? 'clicks' : 'origins';
        $prefix = $filenamePrefix ?: ($platformScope ? 'metricas-plataforma' : 'metricas');

        if ($type === 'clicks') {
            [$headers, $rows] = $this->clicksDataset($tenantId, $start, $end, $filters, $platformScope);
            $filename = "{$prefix}-cliques.{$format}";
            $sheetTitle = 'Cliques';
        } else {
            [$headers, $rows] = $this->originsDataset($tenantId, $start, $end, $filters, $dimension, $platformScope);
            $filename = "{$prefix}-origem.{$format}";
            $sheetTitle = 'Origem';
        }

        return $format === 'xlsx'
            ? $this->streamXlsx($headers, $rows, $filename, $sheetTitle)
            : $this->streamCsv($headers, $rows, $filename);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function originsDataset(
        ?int $tenantId,
        $start,
        $end,
        array $filters,
        string $dimension,
        bool $platformScope,
    ): array {
        $breakdown = $this->analytics->breakdown($tenantId, $start, $end, $dimension, $filters, $platformScope);

        $headers = [
            $dimension, 'visitantes', 'cliques', 'checkouts', 'pix', 'aprovadas',
            'taxa_conversao', 'receita', 'ticket_medio', 'receita_por_clique',
        ];

        $rows = [];
        foreach ($breakdown as $r) {
            $rows[] = [
                $r['label'],
                $r['visitors'],
                $r['clicks'],
                $r['checkouts_started'],
                $r['pix_created'],
                $r['approved'],
                $r['conversion_rate'],
                $r['revenue'],
                $r['avg_ticket'],
                $r['revenue_per_click'],
            ];
        }

        return [$headers, $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function clicksDataset(
        ?int $tenantId,
        $start,
        $end,
        array $filters,
        bool $platformScope,
    ): array {
        /** @var Collection<int, MetricsEvent> $events */
        $events = $this->analytics->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->orderByDesc('occurred_at')
            ->limit(10000)
            ->get();

        if ($platformScope) {
            $headers = [
                'data', 'evento', 'tenant_id', 'ip', 'produto', 'url', 'fonte', 'campanha',
                'dispositivo', 'cidade', 'estado', 'afiliado', 'status', 'valor', 'segundos_conversao',
            ];
            $rows = $events->map(fn (MetricsEvent $e) => [
                optional($e->occurred_at)?->toDateTimeString(),
                $e->event_name,
                $e->tenant_id,
                $e->ip_masked,
                $e->product_id,
                $e->destination_url,
                $e->utm_source,
                $e->utm_campaign,
                $e->device_type,
                $e->city,
                $e->region,
                $e->affiliate_ref,
                $e->conversion_status,
                $e->amount,
                $e->seconds_to_convert,
            ])->values()->all();
        } else {
            $headers = [
                'data', 'evento', 'ip', 'produto', 'url', 'fonte', 'campanha', 'dispositivo',
                'cidade', 'estado', 'afiliado', 'status', 'valor', 'segundos_conversao',
            ];
            $rows = $events->map(fn (MetricsEvent $e) => [
                optional($e->occurred_at)?->toDateTimeString(),
                $e->event_name,
                $e->ip_masked,
                $e->product_id,
                $e->destination_url,
                $e->utm_source,
                $e->utm_campaign,
                $e->device_type,
                $e->city,
                $e->region,
                $e->affiliate_ref,
                $e->conversion_status,
                $e->amount,
                $e->seconds_to_convert,
            ])->values()->all();
        }

        return [$headers, $rows];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function streamCsv(array $headers, array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // BOM para Excel reconhecer UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function streamXlsx(array $headers, array $rows, string $filename, string $sheetTitle): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $sheetTitle) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(mb_substr($sheetTitle, 0, 31));

            $colCount = count($headers);
            for ($c = 0; $c < $colCount; $c++) {
                $sheet->setCellValue([$c + 1, 1], $headers[$c]);
            }
            $sheet->getStyle('1:1')->getFont()->setBold(true);

            $r = 2;
            foreach ($rows as $row) {
                for ($c = 0; $c < $colCount; $c++) {
                    $sheet->setCellValue([$c + 1, $r], $row[$c] ?? null);
                }
                $r++;
            }

            foreach (range(1, $colCount) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
