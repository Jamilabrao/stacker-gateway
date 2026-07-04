<?php



namespace App\Http\Controllers\Platform;



use App\Http\Controllers\Controller;

use App\Models\IntegraxSmsDispatch;

use App\Models\PlatformIntegraxSetting;

use App\Services\Integrax\IntegraxService;

use App\Services\PlatformAuditService;

use App\Support\IntegraxCartRecoverySteps;

use Illuminate\Http\JsonResponse;

use Illuminate\Http\RedirectResponse;

use Illuminate\Http\Request;

use Illuminate\Validation\ValidationException;

use Inertia\Inertia;

use Inertia\Response;



class IntegraxController extends Controller

{

    public function index(): Response

    {

        $settings = PlatformIntegraxSetting::instance();



        $recentDispatches = IntegraxSmsDispatch::query()

            ->orderByDesc('id')

            ->limit(20)

            ->get()

            ->map(fn (IntegraxSmsDispatch $d) => [

                'id' => $d->id,

                'event_type' => $d->event_type,

                'sequence_step' => $d->sequence_step,

                'phone' => $d->phone,

                'status' => $d->status,

                'sent_at' => $d->sent_at?->toIso8601String(),

                'error' => $d->error,

                'created_at' => $d->created_at?->toIso8601String(),

            ])

            ->values()

            ->all();



        return Inertia::render('Platform/Integrax/Index', [

            'settings' => $settings->toPublicArray(),

            'recent_dispatches' => $recentDispatches,

        ]);

    }



    public function update(Request $request): RedirectResponse

    {

        $settings = PlatformIntegraxSetting::instance();

        $hasToken = $settings->api_token !== null && $settings->api_token !== '';



        $validated = $request->validate([

            'is_active' => ['nullable', 'boolean'],

            'sms_checkout_only' => ['nullable', 'boolean'],

            'api_token' => [$hasToken ? 'nullable' : 'required', 'string', 'max:512'],

            'sender_from' => ['required', 'string', 'max:32'],

            'event_cart_recovery_enabled' => ['nullable', 'boolean'],

            'event_order_paid_enabled' => ['nullable', 'boolean'],

            'event_access_granted_enabled' => ['nullable', 'boolean'],

            'event_pix_generated_enabled' => ['nullable', 'boolean'],

            'message_order_paid' => ['nullable', 'string', 'max:160'],

            'message_access_granted' => ['nullable', 'string', 'max:160'],

            'message_pix_generated' => ['nullable', 'string', 'max:160'],

            'cart_recovery_steps' => ['nullable', 'array', 'max:10'],

            'cart_recovery_steps.*.delay_value' => ['required_with:cart_recovery_steps', 'integer', 'min:1', 'max:9999'],

            'cart_recovery_steps.*.delay_unit' => ['required_with:cart_recovery_steps', 'string', 'in:minutes,hours,days'],

            'cart_recovery_steps.*.message' => ['required_with:cart_recovery_steps', 'string', 'max:160'],

        ]);



        try {

            $cartRecoverySteps = IntegraxCartRecoverySteps::fromUiInput(

                is_array($validated['cart_recovery_steps'] ?? null) ? $validated['cart_recovery_steps'] : []

            );

        } catch (\InvalidArgumentException $e) {

            throw ValidationException::withMessages([

                'cart_recovery_steps' => $e->getMessage(),

            ]);

        }



        if ($request->boolean('event_cart_recovery_enabled') && $cartRecoverySteps === []) {

            throw ValidationException::withMessages([

                'cart_recovery_steps' => 'Adicione ao menos uma mensagem de recuperação de carrinho.',

            ]);

        }



        $settings->fill([

            'is_active' => $request->boolean('is_active'),

            'sms_checkout_only' => $request->boolean('sms_checkout_only', true),

            'sender_from' => trim((string) $validated['sender_from']),

            'event_cart_recovery_enabled' => $request->boolean('event_cart_recovery_enabled'),

            'event_order_paid_enabled' => $request->boolean('event_order_paid_enabled'),

            'event_access_granted_enabled' => $request->boolean('event_access_granted_enabled'),

            'event_pix_generated_enabled' => $request->boolean('event_pix_generated_enabled'),

            'message_order_paid' => $validated['message_order_paid'] ?? null,

            'message_access_granted' => $validated['message_access_granted'] ?? null,

            'message_pix_generated' => $validated['message_pix_generated'] ?? null,

            'cart_recovery_steps' => $cartRecoverySteps !== [] ? $cartRecoverySteps : null,

        ]);



        if (! empty($validated['api_token'])) {

            $settings->api_token = $validated['api_token'];

        }



        $settings->save();



        PlatformAuditService::log('platform.integrax.updated', [

            'is_active' => $settings->is_active,

            'sender_from' => $settings->sender_from,

            'cart_recovery_steps_count' => count($cartRecoverySteps),

        ], $request);



        return redirect()->route('plataforma.integrax.index')

            ->with('success', 'Configurações IntegraX salvas.');

    }



    public function test(Request $request, IntegraxService $integraxService): JsonResponse

    {

        $validated = $request->validate([

            'phone' => ['required', 'string', 'max:20'],

            'message' => ['nullable', 'string', 'max:160'],

        ]);



        $settings = PlatformIntegraxSetting::instance();

        if (! $settings->isConfigured() && empty($validated['message'])) {

            return response()->json([

                'success' => false,

                'message' => 'Configure e ative a IntegraX antes de testar, ou informe uma mensagem.',

            ], 422);

        }



        $message = trim((string) ($validated['message'] ?? ''));

        if ($message === '') {

            $message = 'Teste IntegraX — SMS enviado pela plataforma Stacker.';

        }



        try {

            $integraxService->assertMessageLength($message);

            $integraxService->sendSms($validated['phone'], $message, $settings);



            return response()->json(['success' => true]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => $e->getMessage(),

            ], 422);

        }

    }

}

