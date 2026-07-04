<?php

namespace App\Console\Commands;

use App\Models\CheckoutSession;
use App\Models\IntegraxSmsDispatch;
use App\Models\PlatformIntegraxSetting;
use App\Services\Integrax\IntegraxMessageBuilder;
use App\Services\Integrax\IntegraxService;
use App\Services\Integrax\IntegraxSmsDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessIntegraxCartRecoveryCommand extends Command
{
    protected $signature = 'integrax:process-cart-recovery';

    protected $description = 'Envia SMS de recuperação de carrinho via IntegraX conforme sequência configurada na plataforma.';

    public function handle(
        IntegraxService $integraxService,
        IntegraxMessageBuilder $messageBuilder,
        IntegraxSmsDispatcher $dispatcher
    ): int {
        $settings = $integraxService->settings();

        if (! $settings->isConfigured() || ! $settings->isEventEnabled(PlatformIntegraxSetting::EVENT_CART_RECOVERY)) {
            $this->line('IntegraX inativa ou recuperação de carrinho desabilitada.');

            return self::SUCCESS;
        }

        $steps = $settings->cartRecoverySteps();
        if ($steps === []) {
            $this->line('Nenhuma mensagem de recuperação configurada.');

            return self::SUCCESS;
        }

        $maxDelayMinutes = (int) end($steps)['delay_minutes'];
        $windowStart = now()->subMinutes($maxDelayMinutes + 120);

        $sessions = CheckoutSession::query()
            ->whereIn('step', [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED])
            ->whereNull('order_id')
            ->whereNotNull('tenant_id')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('created_at', '>=', $windowStart)
            ->with('product:id,name,checkout_slug')
            ->get();

        $dispatched = 0;

        foreach ($sessions as $session) {
            $phone = $integraxService->normalizePhone($session->phone);
            if ($phone === null) {
                continue;
            }

            $abandonAt = $this->resolveAbandonAt($session);
            if ($abandonAt === null) {
                continue;
            }

            $sentSteps = IntegraxSmsDispatch::sentStepIndicesForSession($session->id);
            $vars = $messageBuilder->fromCheckoutSession($session);

            foreach ($steps as $index => $step) {
                if (in_array($index, $sentSteps, true)) {
                    continue;
                }

                if (IntegraxSmsDispatch::hasPendingStepForSession($session->id, $index)) {
                    break;
                }

                $dueAt = $abandonAt->copy()->addMinutes((int) $step['delay_minutes']);
                if (now()->lt($dueAt)) {
                    break;
                }

                if ($dispatcher->dispatchCartRecoveryStep(
                    $session,
                    $index,
                    (string) $step['message'],
                    $vars
                )) {
                    $dispatched++;
                }

                break;
            }
        }

        if ($dispatched > 0) {
            Log::info('ProcessIntegraxCartRecoveryCommand: SMS enfileirados', ['count' => $dispatched]);
        }

        $this->info("IntegraX cart recovery: {$dispatched} SMS enfileirado(s).");

        return self::SUCCESS;
    }

    private function resolveAbandonAt(CheckoutSession $session): ?Carbon
    {
        $timestamp = $session->form_filled_at
            ?? $session->form_started_at
            ?? $session->updated_at
            ?? $session->created_at;

        return $timestamp instanceof Carbon ? $timestamp : null;
    }
}
