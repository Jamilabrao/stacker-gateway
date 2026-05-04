<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\PaymentService;
use App\Services\SubscriptionRenewalService;
use App\Support\FakeConsumerData;
use Carbon\Carbon;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cobrança automática de renovação (Stripe) usando método guardado na assinatura.
 * Executa de forma síncrona no agendamento (sem ShouldQueue) para não depender de worker.
 */
class ChargeDueSubscriptionsWithSavedCardJob
{
    use Dispatchable;

    public function handle(PaymentService $paymentService, SubscriptionRenewalService $renewalService): void
    {
        $today = Carbon::today();

        $subscriptions = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('saved_payment_method_id')
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', '=', $today->toDateString())
            ->whereHas('subscriptionPlan', fn ($q) => $q->where('interval', '!=', SubscriptionPlan::INTERVAL_LIFETIME))
            ->with(['user', 'product', 'subscriptionPlan', 'savedPaymentMethod'])
            ->get();

        foreach ($subscriptions as $subscription) {
            $method = $subscription->savedPaymentMethod;
            if (! $method || $method->gateway !== 'stripe') {
                continue;
            }
            $periodEndStr = $subscription->current_period_end?->toDateString() ?? '';
            $lockKey = 'sub_stripe_auto_renew:'.$subscription->id.':'.$periodEndStr;
            if (! Cache::add($lockKey, 1, now()->addDays(2))) {
                continue;
            }

            $plan = $subscription->subscriptionPlan;
            $product = $subscription->product;
            $user = $subscription->user;
            if (! $plan || ! $product || ! $user) {
                Cache::forget($lockKey);

                continue;
            }

            $tenantId = (int) $subscription->tenant_id;
            $amount = (float) $plan->price;
            $currency = strtolower($plan->getCurrencyOrDefault());
            $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
            if ($currency !== 'brl') {
                $amount = $currency === 'eur' ? $amount / ($rates['brl_eur'] ?? 0.16) : $amount / ($rates['brl_usd'] ?? 0.18);
            }

            [$periodStart, $periodEnd] = $plan->getCurrentPeriod();

            $order = Order::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'product_offer_id' => null,
                'subscription_plan_id' => $plan->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'is_renewal' => true,
                'amount' => $amount,
                'email' => $user->email,
                'cpf' => null,
                'phone' => null,
                'customer_ip' => null,
                'coupon_code' => null,
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'payment_method' => 'card',
                'metadata' => [
                    'checkout_payment_method' => 'card',
                    'subscription_auto_renewal' => true,
                    'subscription_id' => $subscription->id,
                ],
            ]);

            try {
                $fake = FakeConsumerData::getForGateway($order->id);
                $rawDoc = preg_replace('/\D/', '', (string) ($user->document ?? ''));
                $consumer = [
                    'name' => $user->name ?? $user->email,
                    'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
                    'email' => $user->email,
                    'phone' => '',
                ];
                $pmId = trim((string) $method->gateway_payment_method_id);
                $card = [
                    'payment_token' => $pmId,
                    'card_mask' => $method->last_four ? '**** '.$method->last_four : '',
                    'currency' => $currency,
                ];
                $cardResult = $paymentService->createCardPayment($order, $product, $consumer, $card);
                $status = $cardResult['status'] ?? null;
                if (in_array($status, ['paid', 'settled', 'approved', 'completed'], true)) {
                    $renewalService->applySuccessfulRenewal($order->fresh(), $subscription->fresh(), $plan);
                } else {
                    $order->delete();
                    Cache::forget($lockKey);
                    Log::info('ChargeDueSubscriptionsWithSavedCardJob: pagamento não aprovado na renovação automática.', [
                        'subscription_id' => $subscription->id,
                        'order_id' => $order->id,
                        'status' => $status,
                    ]);
                }
            } catch (\Throwable $e) {
                try {
                    $order->delete();
                } catch (\Throwable) {
                }
                Cache::forget($lockKey);
                Log::warning('ChargeDueSubscriptionsWithSavedCardJob: falha na cobrança automática.', [
                    'subscription_id' => $subscription->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
