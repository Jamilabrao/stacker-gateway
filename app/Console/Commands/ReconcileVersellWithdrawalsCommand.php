<?php

namespace App\Console\Commands;

use App\Models\Withdrawal;
use App\Services\Versell\VersellWithdrawalReconcileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileVersellWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawals:reconcile-versell
                            {--limit=80 : Máximo de saques para checar por execução}
                            {--min-age-minutes=0 : Ignorar registros atualizados há menos de X minutos}
                            {--hours=336 : Janela máxima desde a criação do saque (padrão 14 dias)}
                            {--withdrawal= : ID interno do saque (um registro; ignora min-age)}';

    protected $description = 'Consulta na Versell saques PIX pendentes e marca como pagos ou falhos conforme a API.';

    public function handle(VersellWithdrawalReconcileService $reconcile): int
    {
        if (! Schema::hasTable('withdrawals')) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $minAge = max(0, (int) $this->option('min-age-minutes'));
        $hours = max(1, (int) $this->option('hours'));
        $onlyId = $this->option('withdrawal');

        if ($onlyId !== null && $onlyId !== '') {
            $w = Withdrawal::query()->find((int) $onlyId);
            if ($w === null) {
                $this->error('Saque não encontrado.');

                return self::FAILURE;
            }

            $outcome = $reconcile->reconcile($w);
            if (($outcome['result'] ?? null) === 'paid' || ($outcome['result'] ?? null) === 'failed') {
                $this->info($outcome['message']);

                return self::SUCCESS;
            }

            if (($outcome['result'] ?? null) === null && str_contains($outcome['message'], 'ignorado')) {
                $this->warn($outcome['message']);

                return self::SUCCESS;
            }

            if (str_contains($outcome['message'], 'sem payout_external_id') || str_contains($outcome['message'], 'Falha ao consultar')) {
                $this->error($outcome['message']);

                return self::FAILURE;
            }

            $this->warn($outcome['message']);

            return self::FAILURE;
        }

        $q = Withdrawal::query()
            ->whereIn('status', ['pending', 'processing'])
            ->where('payout_provider', 'versell')
            ->where(function ($query) {
                $query->where(function ($q2) {
                    $q2->whereNotNull('payout_external_id')
                        ->where('payout_external_id', '!=', '');
                })->orWhereNotNull('payout_meta');
            })
            ->where('created_at', '>=', now()->subHours($hours));

        if ($minAge > 0) {
            $q->where('updated_at', '<=', now()->subMinutes($minAge));
        }

        $rows = $q->orderBy('id')->limit($limit)->get();

        $paid = 0;
        $failed = 0;

        foreach ($rows as $withdrawal) {
            $outcome = $reconcile->reconcile($withdrawal);
            if (($outcome['result'] ?? null) === 'paid') {
                $paid++;
            } elseif (($outcome['result'] ?? null) === 'failed') {
                $failed++;
            }
        }

        $this->info("Reconciliados: {$paid} pagos, {$failed} falhos de {$rows->count()} saques Versell.");

        return self::SUCCESS;
    }
}
