<?php

namespace App\Console\Commands;

use App\Events\CartAbandoned;
use App\Models\CheckoutSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FireAbandonedCartWebhooks extends Command
{
    protected $signature = 'checkout:fire-abandoned-cart-webhooks
                            {--minutes= : Minutos desde interação no formulário (padrão: constante do modelo CheckoutSession)}
                            {--tenant= : Filtrar por tenant_id (opcional)}';

    protected $description = 'Dispara eventos CartAbandoned para sessões de checkout não convertidas (form_started ou form_filled), após o período de graça.';

    public function handle(): int
    {
        $minutesOpt = $this->option('minutes');
        $minutes = $minutesOpt !== null && $minutesOpt !== ''
            ? max(1, (int) $minutesOpt)
            : CheckoutSession::ABANDONMENT_GRACE_MINUTES;

        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        $cutoff = now()->subMinutes($minutes);

        $query = CheckoutSession::query()
            ->whereIn('step', [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED])
            ->whereNull('order_id')
            ->whereNull('abandoned_webhook_fired_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereRaw(
                'COALESCE(form_filled_at, form_started_at, updated_at, created_at) <= ?',
                [$cutoff]
            );

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $sessions = $query->with('product')->get();
        $count = 0;
        $skippedNoTenant = 0;

        foreach ($sessions as $session) {
            if ($session->tenant_id === null) {
                $skippedNoTenant++;
                continue;
            }
            event(new CartAbandoned($session));
            $session->update(['abandoned_webhook_fired_at' => now()]);
            $count++;
        }

        if ($skippedNoTenant > 0) {
            Log::info('FireAbandonedCartWebhooks: sessões ignoradas sem tenant_id', [
                'count' => $skippedNoTenant,
            ]);
        }

        $this->info("CartAbandoned disparado para {$count} sessão(ões) (após {$minutes} minuto(s)).");
        if ($skippedNoTenant > 0) {
            $this->line("Ignoradas {$skippedNoTenant} sessão(ões) sem tenant_id.");
        }

        return self::SUCCESS;
    }
}
