<?php

namespace App\Console\Commands;

use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Services\MerchantWithdrawalService;
use App\Services\WithdrawalPixReceiptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileCajuPayWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawals:reconcile-cajupay
                            {--limit=80 : Máximo de saques para checar por execução}
                            {--min-age-minutes=0 : Ignorar registros atualizados há menos de X minutos}
                            {--hours=48 : Janela máxima desde a criação do saque}
                            {--withdrawal= : ID interno do saque (um registro; ignora min-age)}';

    protected $description = 'Consulta na CajuPay saques PIX pendentes e marca como pagos ou falhos (cancelados) conforme a API.';

    public function handle(CajuPayPayoutService $payoutService): int
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
            if (! in_array($w->status, ['pending', 'processing'], true) || $w->payout_provider !== 'cajupay') {
                $this->warn('Saque ignorado (não está pending/processing cajupay).');

                return self::SUCCESS;
            }
            $tx = trim((string) $w->payout_external_id);
            if ($tx === '') {
                $this->error('Saque sem payout_external_id; não é possível consultar na CajuPay.');

                return self::FAILURE;
            }

            return $this->reconcileOne($payoutService, $w, $tx) ? self::SUCCESS : self::FAILURE;
        }

        $q = Withdrawal::query()
            ->whereIn('status', ['pending', 'processing'])
            ->where('payout_provider', 'cajupay')
            ->whereNotNull('payout_external_id')
            ->where('payout_external_id', '!=', '')
            ->where('created_at', '>=', now()->subHours($hours));

        if ($minAge > 0) {
            $q->where('updated_at', '<=', now()->subMinutes($minAge));
        }

        $rows = $q->orderBy('id')->limit($limit)->get();

        $paid = 0;
        $failed = 0;

        foreach ($rows as $withdrawal) {
            $tx = trim((string) $withdrawal->payout_external_id);
            if ($tx === '') {
                continue;
            }

            $result = $this->applyApiStatus($payoutService, $withdrawal, $tx);
            if ($result === 'paid') {
                $paid++;
            } elseif ($result === 'failed') {
                $failed++;
            }
        }

        if ($paid > 0) {
            $this->info("Marcados como pagos: {$paid}.");
        }
        if ($failed > 0) {
            $this->info("Marcados como falhos (saldo devolvido): {$failed}.");
        }

        return self::SUCCESS;
    }

    private function reconcileOne(CajuPayPayoutService $payoutService, Withdrawal $withdrawal, string $externalId): bool
    {
        $result = $this->applyApiStatus($payoutService, $withdrawal, $externalId);
        if ($result === 'paid') {
            $this->info('Saque marcado como pago.');

            return true;
        }
        if ($result === 'failed') {
            $this->info('Saque marcado como falho e saldo devolvido.');

            return true;
        }

        $this->warn('API retornou status: '.($result ?? 'null').' (esperado paid ou failed/cancelled).');

        return false;
    }

    /**
     * @return 'paid'|'failed'|null
     */
    private function applyApiStatus(CajuPayPayoutService $payoutService, Withdrawal $withdrawal, string $externalId): ?string
    {
        try {
            $apiStatus = $payoutService->getPayoutSettlementStatus($externalId, (int) $withdrawal->tenant_id);
        } catch (\Throwable) {
            return null;
        }

        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta['reconcile_last_at'] = now()->toIso8601String();
        $meta['reconcile_last_api_status'] = $apiStatus;
        $withdrawal->update(['payout_meta' => $meta]);

        if ($apiStatus === 'paid') {
            $fresh = $withdrawal->fresh();
            app(WithdrawalPixReceiptService::class)->enrichFromCajuPay($fresh);
            MerchantWithdrawalService::markPaid($fresh);

            return 'paid';
        }

        if ($apiStatus === 'failed') {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout CajuPay cancelado ou falhou (reconciliação automática).'
            );

            return 'failed';
        }

        return $apiStatus;
    }
}
