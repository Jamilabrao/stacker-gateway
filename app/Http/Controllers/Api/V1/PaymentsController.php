<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\CreatePixChargeJob;
use App\Models\Order;
use App\Services\Api\ApiAuthContext;
use App\Services\Api\ApiIdempotencyService;
use App\Services\ApiPixAccess;
use App\Services\MerchantOperationalGuard;
use App\Services\PaymentService;
use App\Events\OrderPending;
use App\Events\PixGenerated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentsController extends Controller
{
    use Concerns\HandlesApiPayments;

    public function createPix(Request $request, ApiIdempotencyService $idempotency): JsonResponse
    {
        $ctx = $this->resolvePaymentContext($request, 'payments:write');
        $validated = $request->validate([
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.cpf' => ['nullable', 'string', 'max:14'],
            'customer.phone' => ['nullable', 'string', 'max:24'],
            'amount' => $this->apiPaymentAmountRules((int) $ctx->application->tenant_id),
            'currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'product_id' => ['nullable', 'string', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'metadata' => ['nullable', 'array'],
            'partner_checkout_url' => ['nullable', 'string', 'max:512', 'regex:/^https:\/\//i'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $idemKey = $request->input('idempotency_key') ?: $request->header('Idempotency-Key');

        return $idempotency->handle(
            $ctx->application,
            $ctx->apiKey,
            is_string($idemKey) ? $idemKey : null,
            $request->getContent() ?: json_encode($validated),
            fn () => $this->doCreatePix($request, $ctx, $validated),
        );
    }

    private function doCreatePix(Request $request, ApiAuthContext $ctx, array $validated): JsonResponse
    {
        $app = $ctx->application;
        $userConsumer = $this->validateCustomerAndGetUser($request, $app, $ctx);
        $amount = (float) $validated['amount'];
        $currency = strtoupper((string) ($validated['currency'] ?? 'BRL'));
        $metadata = $validated['metadata'] ?? [];
        $metadata['source'] = 'api';
        $partnerUrl = trim((string) ($validated['partner_checkout_url'] ?? ''));
        if ($partnerUrl !== '') {
            $metadata['partner_checkout_url'] = $partnerUrl;
        }

        $ctxData = $this->createOrderForApi(
            $request,
            $app,
            $amount,
            $currency,
            $validated['product_id'] ?? null,
            $validated['product_offer_id'] ?? null,
            $validated['subscription_plan_id'] ?? null,
            'pix',
            $metadata,
            $userConsumer,
            $ctx,
        );

        $order = $ctxData['order'];
        event(new OrderPending($order));

        if ($ctx->wantsAsyncPayments($request)) {
            CreatePixChargeJob::dispatch($order->id);

            return response()->json([
                'order_id' => $order->id,
                'status' => 'processing',
            ], 202);
        }

        $paymentService = app(PaymentService::class);

        try {
            $result = $paymentService->createPixPayment($order, $ctxData['product'], $ctxData['consumer'], null);
            event(new PixGenerated($order, [
                'qrcode' => $result['qrcode'] ?? null,
                'copy_paste' => $result['copy_paste'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
            ]));

            return response()->json([
                'order_id' => $order->id,
                'transaction_id' => $result['transaction_id'] ?? null,
                'qrcode' => $result['qrcode'] ?? null,
                'copy_paste' => $result['copy_paste'] ?? null,
                'status' => 'pending',
            ], 201);
        } catch (\Throwable $e) {
            $order->delete();

            return response()->json([
                'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX.',
            ], 422);
        }
    }

    public function createCard(Request $request, ApiIdempotencyService $idempotency): JsonResponse
    {
        $ctx = $this->resolvePaymentContext($request, 'payments:write');
        $validated = $request->validate([
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.cpf' => ['nullable', 'string', 'max:14'],
            'customer.phone' => ['nullable', 'string', 'max:24'],
            'amount' => $this->apiPaymentAmountRules((int) $ctx->application->tenant_id),
            'currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'product_id' => ['nullable', 'string', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'metadata' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'card' => ['required', 'array'],
            'card.payment_token' => ['required', 'string', 'max:10000'],
        ]);

        $idemKey = $request->input('idempotency_key') ?: $request->header('Idempotency-Key');

        return $idempotency->handle(
            $ctx->application,
            $ctx->apiKey,
            is_string($idemKey) ? $idemKey : null,
            $request->getContent() ?: json_encode($validated),
            fn () => $this->doCreateCard($request, $ctx, $validated),
        );
    }

    private function doCreateCard(Request $request, ApiAuthContext $ctx, array $validated): JsonResponse
    {
        $app = $ctx->application;
        $userConsumer = $this->validateCustomerAndGetUser($request, $app, $ctx);
        $amount = (float) $validated['amount'];
        $currency = strtoupper((string) ($validated['currency'] ?? 'BRL'));
        $metadata = $validated['metadata'] ?? [];
        $metadata['source'] = 'api';

        $ctxData = $this->createOrderForApi(
            $request,
            $app,
            $amount,
            $currency,
            $validated['product_id'] ?? null,
            $validated['product_offer_id'] ?? null,
            $validated['subscription_plan_id'] ?? null,
            'card',
            $metadata,
            $userConsumer,
            $ctx,
        );

        $order = $ctxData['order'];
        $paymentService = app(PaymentService::class);
        $card = ['payment_token' => $validated['card']['payment_token'], 'card_mask' => $validated['card']['card_mask'] ?? null];

        try {
            event(new OrderPending($order));
            $result = $paymentService->createCardPayment($order, $ctxData['product'], $ctxData['consumer'], $card, null);
            $status = $result['status'] ?? 'pending';
            if (in_array($status, ['paid', 'settled', 'approved', 'completed'], true)) {
                $order->update(['status' => 'completed', 'payment_method' => 'card']);
                $order->grantPurchasedProductAccessToBuyer();
                event(new \App\Events\OrderCompleted($order));
            }

            $response = [
                'order_id' => $order->id,
                'transaction_id' => $result['transaction_id'] ?? null,
                'status' => $status,
            ];
            if (isset($result['client_secret'])) {
                $response['client_secret'] = $result['client_secret'];
            }

            return response()->json($response, 201);
        } catch (\Throwable $e) {
            $order->delete();

            return response()->json([
                'message' => $e->getMessage() ?: 'Falha no pagamento com cartão.',
            ], 422);
        }
    }

    public function createBoleto(Request $request, ApiIdempotencyService $idempotency): JsonResponse
    {
        $ctx = $this->resolvePaymentContext($request, 'payments:write');
        $validated = $request->validate([
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.cpf' => ['nullable', 'string', 'max:14'],
            'customer.phone' => ['nullable', 'string', 'max:24'],
            'amount' => $this->apiPaymentAmountRules((int) $ctx->application->tenant_id),
            'currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'product_id' => ['nullable', 'string', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'metadata' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $idemKey = $request->input('idempotency_key') ?: $request->header('Idempotency-Key');

        return $idempotency->handle(
            $ctx->application,
            $ctx->apiKey,
            is_string($idemKey) ? $idemKey : null,
            $request->getContent() ?: json_encode($validated),
            fn () => $this->doCreateBoleto($request, $ctx, $validated),
        );
    }

    private function doCreateBoleto(Request $request, ApiAuthContext $ctx, array $validated): JsonResponse
    {
        $app = $ctx->application;
        $userConsumer = $this->validateCustomerAndGetUser($request, $app, $ctx);
        $amount = (float) $validated['amount'];
        $currency = strtoupper((string) ($validated['currency'] ?? 'BRL'));
        $metadata = $validated['metadata'] ?? [];
        $metadata['source'] = 'api';

        $ctxData = $this->createOrderForApi(
            $request,
            $app,
            $amount,
            $currency,
            $validated['product_id'] ?? null,
            $validated['product_offer_id'] ?? null,
            $validated['subscription_plan_id'] ?? null,
            'boleto',
            $metadata,
            $userConsumer,
            $ctx,
        );

        $order = $ctxData['order'];
        $paymentService = app(PaymentService::class);

        try {
            event(new OrderPending($order));
            $result = $paymentService->createBoletoPayment($order, $ctxData['product'], $ctxData['consumer'], null);
            $boletoData = [
                'amount' => $result['amount'] ?? $order->amount,
                'expire_at' => $result['expire_at'] ?? null,
                'barcode' => $result['barcode'] ?? null,
                'pdf_url' => $result['pdf_url'] ?? null,
            ];
            event(new \App\Events\BoletoGenerated($order, $boletoData));

            return response()->json([
                'order_id' => $order->id,
                'transaction_id' => $result['transaction_id'] ?? null,
                'barcode' => $result['barcode'] ?? '',
                'pdf_url' => $result['pdf_url'] ?? '',
                'expire_at' => $result['expire_at'] ?? '',
                'amount' => $result['amount'] ?? $order->amount,
                'status' => 'pending',
            ], 201);
        } catch (\Throwable $e) {
            $order->delete();

            return response()->json([
                'message' => $e->getMessage() ?: 'Não foi possível gerar o boleto.',
            ], 422);
        }
    }
}
