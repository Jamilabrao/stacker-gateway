<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CheckoutTrackingController extends Controller
{
    public function track(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'step' => ['required', 'string', 'in:form_started,form_filled'],
            'email' => ['nullable', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'cpf' => ['nullable', 'string', 'max:14'],
            'phone' => ['nullable', 'string', 'max:24'],
        ]);

        $session = CheckoutSession::where('session_token', $validated['session_token'])->first();

        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Sessão não encontrada.'], 404);
        }

        $step = $validated['step'];
        if ($step === CheckoutSession::STEP_FORM_FILLED && $session->step === CheckoutSession::STEP_CONVERTED) {
            return response()->json(['success' => true]);
        }

        if (in_array($session->step, [CheckoutSession::STEP_CONVERTED], true)) {
            return response()->json(['success' => true]);
        }

        try {
            $this->applyTrackingUpdates($session, $validated, $step);
        } catch (\Throwable $e) {
            Log::warning('checkout.track failed', [
                'session_id' => $session->id,
                'step' => $step,
                'message' => $e->getMessage(),
            ]);

            // Tracking é auxiliar (carrinho abandonado); não quebrar o checkout por falha aqui.
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyTrackingUpdates(CheckoutSession $session, array $validated, string $step): void
    {
        $isContactResync = $step === CheckoutSession::STEP_FORM_FILLED
            && in_array($session->step, [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED], true);

        $updates = ['step' => $step];
        if ($isContactResync) {
            $updates['step'] = CheckoutSession::STEP_FORM_FILLED;
        }

        if ($step === CheckoutSession::STEP_FORM_STARTED && $session->form_started_at === null) {
            $updates['form_started_at'] = now();
        }
        if ($step === CheckoutSession::STEP_FORM_FILLED) {
            if ($session->form_started_at === null) {
                $updates['form_started_at'] = now();
            }
            if ($session->form_filled_at === null) {
                $updates['form_filled_at'] = now();
            }
        }

        $contactChanged = $this->mergeContactFields($session, $validated, $updates);

        if ($isContactResync && $contactChanged) {
            $updates['form_filled_at'] = now();
        }

        $session->update($updates);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $updates
     */
    private function mergeContactFields(CheckoutSession $session, array $validated, array &$updates): bool
    {
        $changed = false;

        if (! empty($validated['email'])) {
            $email = (string) $validated['email'];
            if ($session->email !== $email) {
                $changed = true;
            }
            $updates['email'] = $email;
        }
        if (array_key_exists('name', $validated)) {
            $name = $validated['name'];
            $normalized = is_string($name) && trim($name) !== '' ? trim($name) : null;
            if ($session->name !== $normalized) {
                $changed = true;
            }
            $updates['name'] = $normalized;
        }
        if (! empty($validated['cpf']) && Schema::hasColumn('checkout_sessions', 'cpf')) {
            $digits = preg_replace('/\D/', '', (string) $validated['cpf']);
            if ($digits !== '' && $session->cpf !== $digits) {
                $changed = true;
            }
            if ($digits !== '') {
                $updates['cpf'] = $digits;
            }
        }
        if (! empty($validated['phone']) && Schema::hasColumn('checkout_sessions', 'phone')) {
            $phone = trim((string) $validated['phone']);
            if ($session->phone !== $phone) {
                $changed = true;
            }
            $updates['phone'] = $phone;
        }

        return $changed;
    }
}
