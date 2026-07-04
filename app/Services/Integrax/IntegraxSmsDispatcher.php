<?php



namespace App\Services\Integrax;



use App\Jobs\IntegraxSendSmsJob;

use App\Models\CheckoutSession;

use App\Models\IntegraxSmsDispatch;

use App\Models\PlatformIntegraxSetting;

use App\Support\IntegraxSmsEligibility;

use Illuminate\Support\Facades\Log;



class IntegraxSmsDispatcher

{

    public function __construct(

        private IntegraxService $integraxService,

        private IntegraxMessageBuilder $messageBuilder

    ) {}



    /**

     * @param  array<string, string>  $vars

     */

    public function dispatch(

        string $eventType,

        string $phone,

        array $vars,

        ?int $tenantId = null,

        ?int $checkoutSessionId = null,

        ?int $orderId = null

    ): bool {

        $settings = $this->integraxService->settings();



        if (! $settings->isConfigured() || ! $settings->isEventEnabled($eventType)) {

            return false;

        }



        if ($orderId !== null && ! IntegraxSmsEligibility::allowsForOrderId($orderId, $settings)) {

            Log::debug('IntegraxSmsDispatcher: pedido via API PIX — SMS ignorado', [

                'event_type' => $eventType,

                'order_id' => $orderId,

            ]);



            return false;

        }



        $normalized = $this->integraxService->normalizePhone($phone);

        if ($normalized === null) {

            Log::debug('IntegraxSmsDispatcher: telefone inválido', [

                'event_type' => $eventType,

                'order_id' => $orderId,

                'checkout_session_id' => $checkoutSessionId,

            ]);



            return false;

        }



        if ($orderId !== null && IntegraxSmsDispatch::alreadyQueuedForOrder($orderId, $eventType)) {

            return false;

        }



        try {

            $message = $this->integraxService->buildMessageForEvent($eventType, $vars, $settings);

        } catch (\Throwable $e) {

            Log::warning('IntegraxSmsDispatcher: falha ao montar mensagem', [

                'event_type' => $eventType,

                'order_id' => $orderId,

                'message' => $e->getMessage(),

            ]);



            return false;

        }



        return $this->queueDispatch(

            eventType: $eventType,

            phone: $normalized,

            message: $message,

            tenantId: $tenantId,

            checkoutSessionId: $checkoutSessionId,

            orderId: $orderId,

        );

    }



    /**

     * @param  array<string, string>  $vars

     */

    public function dispatchCartRecoveryStep(

        CheckoutSession $session,

        int $stepIndex,

        string $template,

        array $vars

    ): bool {

        $settings = $this->integraxService->settings();



        if (! $settings->isConfigured() || ! $settings->isEventEnabled(PlatformIntegraxSetting::EVENT_CART_RECOVERY)) {

            return false;

        }



        $phone = $this->integraxService->normalizePhone($session->phone);

        if ($phone === null) {

            return false;

        }



        if (IntegraxSmsDispatch::hasPendingStepForSession($session->id, $stepIndex)) {

            return false;

        }



        $sentSteps = IntegraxSmsDispatch::sentStepIndicesForSession($session->id);

        if (in_array($stepIndex, $sentSteps, true)) {

            return false;

        }



        try {

            $message = $this->integraxService->buildMessageFromTemplate($template, $vars);

        } catch (\Throwable $e) {

            Log::warning('IntegraxSmsDispatcher: falha ao montar mensagem de recuperação', [

                'checkout_session_id' => $session->id,

                'step_index' => $stepIndex,

                'message' => $e->getMessage(),

            ]);



            return false;

        }



        return $this->queueDispatch(

            eventType: PlatformIntegraxSetting::EVENT_CART_RECOVERY,

            phone: $phone,

            message: $message,

            tenantId: $session->tenant_id,

            checkoutSessionId: $session->id,

            orderId: null,

            sequenceStep: $stepIndex,

        );

    }



    private function queueDispatch(

        string $eventType,

        string $phone,

        string $message,

        ?int $tenantId,

        ?int $checkoutSessionId,

        ?int $orderId,

        ?int $sequenceStep = null,

    ): bool {

        $dispatch = IntegraxSmsDispatch::query()->create([

            'tenant_id' => $tenantId,

            'checkout_session_id' => $checkoutSessionId,

            'order_id' => $orderId,

            'event_type' => $eventType,

            'sequence_step' => $sequenceStep,

            'phone' => $phone,

            'message' => $message,

            'status' => IntegraxSmsDispatch::STATUS_PENDING,

        ]);



        IntegraxSendSmsJob::dispatch($dispatch->id)->onQueue((string) config('integrax.queue', 'integrax-sms'));



        return true;

    }

}

