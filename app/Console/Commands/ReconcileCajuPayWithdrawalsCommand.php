<?php

namespace App\Console\Commands;

use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Services\MerchantWithdrawalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileCajuPayWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawals:reconcile-cajupay
                            {--limit=80 : Máximo de saques para checar por execução}
                            {--min-age-minutes=0 : Ignorar registros atualizados há menos de X minutos}
                            {--withdrawal= : ID interno do saque (um registro; ignora min-age)}';

    protected $description = 'Consulta na CajuPay saques PIX automáticos ainda pendentes e marca como pagos quando a API já liquidou.';

    public function handle(CajuPayPayoutService $payoutService): int
    {
        if (! Schema::hasTable('withdrawals')) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $minAge = max(0, (int) $this->option('min-age-minutes'));
        $onlyId = $this->option('withdrawal');

        if ($onlyId !== null && $onlyId !== '') {
            $w = Withdrawal::query()->find((int) $onlyId);
            if ($w === null) {
                $this->error('Saque não encontrado.');

                return self::FAILURE;
            }
            if (! in_array($w->status, ['pending', 'processing'], true) || $w->payout_provider !== 'cajupay') {
                $this->warn('Saque ignorado (não está pending/processing cajupay).');

                return self::SUCCESS;
            }
            $tx = trim((string) $w->payout_external_id);
            if ($tx === '') {
                $this->error('Saque sem payout_external_id; não é possível consultar na CajuPay.');

                return self::FAILURE;
            }
            $apiStatus = $payoutService->getPayoutSettlementStatus($tx);
            if ($apiStatus === 'paid') {
                MerchantWithdrawalService::markPaid($w->fresh());
                $this->info('Saque marcado como pago.');
            } else {
                $this->warn('API retornou status: '.($apiStatus ?? 'null').' (esperado paid após liquidação).');
            }

            return self::SUCCESS;
        }

        $q = Withdrawal::query()
            ->whereIn('status', ['pending', 'processing'])
            ->where('payout_provider', 'cajupay')
            ->whereNotNull('payout_external_id')
            ->where('payout_external_id', '!=', '')
            ->where('created_at', '>=', now()->subHours(2));

        if ($minAge > 0) {
            $q->where('updated_at', '<=', now()->subMinutes($minAge));
        }

        $rows = $q->orderBy('id')->limit($limit)->get();

        $paid = 0;

        foreach ($rows as $withdrawal) {
            $tx = trim((string) $withdrawal->payout_external_id);
            if ($tx === '') {
                continue;
            }

            try {
                $apiStatus = $payoutService->getPayoutSettlementStatus($tx);
            } catch (\Throwable) {
                $apiStatus = null;
            }

            if ($apiStatus === 'paid') {
                MerchantWithdrawalService::markPaid($withdrawal->fresh());
                $paid++;
            }
        }

        if ($paid > 0) {
            $this->info("Marcados como pagos: {$paid}.");
        }

        return self::SUCCESS;
    }
}
