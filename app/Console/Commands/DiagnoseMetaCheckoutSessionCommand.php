<?php

namespace App\Console\Commands;

use App\Services\Meta\MetaCheckoutSessionDiagnostics;
use Illuminate\Console\Command;

class DiagnoseMetaCheckoutSessionCommand extends Command
{
    protected $signature = 'meta:diagnose-session
                            {session : Token da sessão ou ID numérico}
                            {--recent : Listar últimas 10 sessões com resumo}';

    protected $description = 'Diagnóstico Meta PageView/InitiateCheckout por sessão de checkout.';

    public function handle(MetaCheckoutSessionDiagnostics $diagnostics): int
    {
        if ($this->option('recent')) {
            $sessions = \App\Models\CheckoutSession::query()
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            foreach ($sessions as $session) {
                $data = $diagnostics->diagnose($session);
                $this->line(sprintf(
                    '#%s token=%s utm=%s fbclid=%s events=%d',
                    $session->id,
                    substr((string) $session->session_token, 0, 8).'…',
                    $data['utm_source'] ?? '—',
                    $data['meta_fbclid'] ? 'sim' : 'não',
                    count($data['meta_tracking_events'] ?? [])
                ));
            }
            $this->newLine();
            $this->line('Use: php artisan meta:diagnose-session {token_ou_id}');

            return self::SUCCESS;
        }

        $session = $diagnostics->findByTokenOrId((string) $this->argument('session'));
        if (! $session) {
            $this->error('Sessão não encontrada.');

            return self::FAILURE;
        }

        $this->line($diagnostics->formatReport($diagnostics->diagnose($session)));

        return self::SUCCESS;
    }
}
