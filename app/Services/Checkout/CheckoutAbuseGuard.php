<?php

namespace App\Services\Checkout;

use App\Models\ApiCheckoutSession;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Support\CheckoutTurnstileSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regras anti-abuso no checkout público (honeypot, sessão, duplicados, Turnstile).
 */
final class CheckoutAbuseGuard
{
    public function __construct(
        private readonly TurnstileVerifier $turnstileVerifier,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Dados já validados do request
     */
    public function assertCanProcess(
        Request $request,
        Product $product,
        array $validated,
        bool $skipForPreview = false,
    ): void {
        if ($skipForPreview) {
            return;
        }

        $paymentMethod = strtolower((string) ($validated['payment_method'] ?? ''));

        $this->assertHoneypotEmpty($request);
        $session = $this->resolveAndValidateSession($request, $product, $validated);
        $this->assertMinTimeOnCheckout($request, $session);
        $this->assertDuplicatePendingOrders($request, $product, $validated, $paymentMethod);
        $this->assertTurnstile($request, $paymentMethod);
    }

    /**
     * Checkout Pro (API hospedado): sessão ApiCheckoutSession, sem CheckoutSession do slug.
     *
     * @param  array<string, mixed>  $validated
     */
    public function assertCanProcessApiHosted(
        Request $request,
        Product $product,
        array $validated,
        ApiCheckoutSession $apiSession,
    ): void {
        $paymentMethod = strtolower((string) ($validated['payment_method'] ?? ''));

        $this->assertHoneypotEmpty($request);
        $this->assertMinTimeOnApiSession($request, $apiSession);
        $customer = is_array($apiSession->customer) ? $apiSession->customer : [];
        $email = strtolower(trim((string) ($customer['email'] ?? '')));
        $this->assertDuplicatePendingOrders($request, $product, [
            'email' => $email,
            'product_id' => $product->id,
        ], $paymentMethod);
        $this->assertTurnstile($request, $paymentMethod);
    }

    /**
     * Checkout API hospedado sem produto (valor aberto): honeypot, tempo mínimo e Turnstile.
     *
     * @param  array<string, mixed>  $validated
     */
    public function assertCanProcessApiAmountOnly(
        Request $request,
        array $validated,
        ApiCheckoutSession $apiSession,
    ): void {
        $paymentMethod = strtolower((string) ($validated['payment_method'] ?? ''));

        $this->assertHoneypotEmpty($request);
        $this->assertMinTimeOnApiSession($request, $apiSession);
        $this->assertTurnstile($request, $paymentMethod);
    }

    private function assertMinTimeOnApiSession(Request $request, ApiCheckoutSession $session): void
    {
        $minSeconds = (int) config('getfy.checkout_security.min_seconds_before_pay', 4);
        if ($minSeconds <= 0 || $session->created_at === null) {
            return;
        }

        $elapsed = (int) $session->created_at->diffInSeconds(now(), absolute: true);
        if ($elapsed < $minSeconds) {
            $this->block($request, 'min_time', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Resposta em cache por fingerprint (IP + email + produto + método), mitiga rotação de idempotency_key.
     *
     * @return RedirectResponse|JsonResponse|null
     */
    public function cachedResponseForFingerprint(Request $request, array $validated): RedirectResponse|JsonResponse|null
    {
        $paymentMethod = strtolower((string) ($validated['payment_method'] ?? ''));
        $email = strtolower(trim((string) ($validated['email'] ?? '')));
        $productId = (int) ($validated['product_id'] ?? 0);
        if ($productId < 1 || $email === '') {
            return null;
        }

        $ttl = (int) config('getfy.checkout_security.server_idempotency_ttl_seconds', 120);
        $key = 'checkout_fp:'.sha1(implode('|', [
            $request->ip(),
            $email,
            (string) $productId,
            $paymentMethod,
        ]));

        $cached = Cache::get($key);
        if ($cached === null || ! is_array($cached)) {
            return null;
        }

        if (($cached['type'] ?? '') === 'redirect' && ! empty($cached['url'])) {
            return redirect($cached['url']);
        }
        if (($cached['type'] ?? '') === 'json' && array_key_exists('data', $cached)) {
            return response()->json($cached['data']);
        }

        return null;
    }

    public function rememberFingerprintResponse(Request $request, array $validated, RedirectResponse|JsonResponse $response): void
    {
        $paymentMethod = strtolower((string) ($validated['payment_method'] ?? ''));
        $email = strtolower(trim((string) ($validated['email'] ?? '')));
        $productId = (int) ($validated['product_id'] ?? 0);
        if ($productId < 1 || $email === '') {
            return;
        }

        $ttl = (int) config('getfy.checkout_security.server_idempotency_ttl_seconds', 120);
        $key = 'checkout_fp:'.sha1(implode('|', [
            $request->ip(),
            $email,
            (string) $productId,
            $paymentMethod,
        ]));

        if ($response instanceof JsonResponse) {
            Cache::put($key, [
                'type' => 'json',
                'data' => $response->getData(true),
            ], $ttl);

            return;
        }

        $target = $response->getTargetUrl();
        if ($target !== '') {
            Cache::put($key, [
                'type' => 'redirect',
                'url' => $target,
            ], $ttl);
        }
    }

    private function assertHoneypotEmpty(Request $request): void
    {
        $hp = trim((string) $request->input('website', ''));
        if ($hp === '') {
            $hp = trim((string) $request->input('_hp', ''));
        }
        if ($hp !== '') {
            $this->block($request, 'honeypot', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveAndValidateSession(Request $request, Product $product, array $validated): CheckoutSession
    {
        $token = trim((string) ($validated['checkout_session_token'] ?? ''));
        if ($token === '') {
            $this->block($request, 'missing_session_token', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $session = CheckoutSession::query()
            ->where('session_token', $token)
            ->where('product_id', $product->id)
            ->first();

        if (! $session) {
            $this->block($request, 'invalid_session', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $maxAgeHours = (int) config('getfy.checkout_security.session_max_age_hours', 2);
        if ($session->created_at !== null && $session->created_at->lt(now()->subHours($maxAgeHours))) {
            $this->block($request, 'session_expired', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sessionIp = trim((string) ($session->customer_ip ?? ''));
        $requestIp = $request->ip();
        if ($sessionIp !== '' && $requestIp !== '' && $sessionIp !== $requestIp) {
            Log::info('checkout.session_ip_mismatch', [
                'product_id' => $product->id,
                'session_ip' => $sessionIp,
                'request_ip' => $requestIp,
            ]);
        }

        return $session;
    }

    private function assertMinTimeOnCheckout(Request $request, CheckoutSession $session): void
    {
        $minSeconds = (int) config('getfy.checkout_security.min_seconds_before_pay', 4);
        if ($minSeconds <= 0 || $session->created_at === null) {
            return;
        }

        $elapsed = (int) $session->created_at->diffInSeconds(now(), absolute: true);
        if ($elapsed < $minSeconds) {
            $this->block($request, 'min_time', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertDuplicatePendingOrders(Request $request, Product $product, array $validated, string $paymentMethod): void
    {
        if (! in_array($paymentMethod, ['pix', 'boleto'], true)) {
            return;
        }

        $email = strtolower(trim((string) ($validated['email'] ?? '')));
        if ($email === '') {
            return;
        }

        $minutes = (int) config('getfy.checkout_security.duplicate_pending_minutes', 15);
        $max = (int) config('getfy.checkout_security.max_pending_per_email', 3);

        $count = Order::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->id)
            ->where('status', 'pending')
            ->where('email', $email)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();

        if ($count >= $max) {
            $this->block($request, 'duplicate_pending', Response::HTTP_TOO_MANY_REQUESTS);
        }
    }

    private function assertTurnstile(Request $request, string $paymentMethod): void
    {
        if (! CheckoutTurnstileSettings::requiresTokenForPaymentMethod($paymentMethod)) {
            return;
        }

        $token = trim((string) $request->input('turnstile_token', ''));
        if ($token === '') {
            $this->block($request, 'turnstile_missing', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->turnstileVerifier->verify($token, $request->ip())) {
            $this->block($request, 'turnstile_invalid', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function block(Request $request, string $reason, int $status): never
    {
        Log::warning('checkout.abuse_blocked', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'product_id' => $request->input('product_id'),
            'payment_method' => $request->input('payment_method'),
        ]);

        $message = match ($reason) {
            'honeypot' => 'Não foi possível processar o pedido.',
            'missing_session_token', 'invalid_session', 'session_expired' => 'Sessão de checkout inválida. Recarregue a página e tente novamente.',
            'min_time' => 'Aguarde alguns segundos antes de finalizar o pagamento.',
            'duplicate_pending' => 'Já existe um pagamento pendente para este e-mail. Conclua ou aguarde antes de gerar outro.',
            'turnstile_missing', 'turnstile_invalid' => 'Confirme que você não é um robô e tente novamente.',
            default => 'Não foi possível processar o pedido. Tente novamente.',
        };

        // Inertia form.post: ValidationException exibe erro no formulário (abort(422) vira página Symfony).
        if ($request->header('X-Inertia')) {
            $exception = ValidationException::withMessages([
                'payment_method' => [$message],
            ]);
            if ($status !== Response::HTTP_UNPROCESSABLE_ENTITY) {
                $exception->status($status);
            }
            throw $exception;
        }

        if ($request->expectsJson()) {
            response()->json(['message' => $message], $status)->throwResponse();
        }

        abort($status, $message);
    }
}
