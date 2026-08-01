<?php

namespace App\Console\Commands;

use App\Services\SalesAchievementGrantService;
use Illuminate\Console\Command;

class ReconcileSalesAchievementsCommand extends Command
{
    protected $signature = 'conquistas:reconcile {--metric=revenue : Tipo de métrica}';

    protected $description = 'Reconcilia conquistas de vendas e concede unlocks pendentes (idempotente)';

    public function handle(SalesAchievementGrantService $grant): int
    {
        $metric = (string) $this->option('metric');
        $count = $grant->reconcileAll($metric);
        $this->info("Conquistas concedidas nesta execução: {$count}");

        return self::SUCCESS;
    }
}
