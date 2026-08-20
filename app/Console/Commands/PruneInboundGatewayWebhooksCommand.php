<?php

namespace App\Console\Commands;

use App\Models\InboundGatewayWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PruneInboundGatewayWebhooksCommand extends Command
{
    protected $signature = 'inbound-webhooks:prune
                            {--days=14 : Manter registros dos últimos N dias}';

    protected $description = 'Remove webhooks inbound antigos da listagem do admin';

    public function handle(): int
    {
        if (! Schema::hasTable('inbound_gateway_webhooks')) {
            $this->info('Tabela inbound_gateway_webhooks inexistente.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $deleted = InboundGatewayWebhook::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Removidos {$deleted} webhook(s) com mais de {$days} dia(s).");

        return self::SUCCESS;
    }
}
