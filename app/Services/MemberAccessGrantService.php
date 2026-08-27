<?php

namespace App\Services;

use App\Events\SubscriptionCreated;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;

/**
 * Concede / revoga acesso à área de membros de forma consistente com Product::hasMemberAreaAccess().
 *
 * Matrícula manual (painel Alunos, import, Member Builder) costumava só gravar product_user.
 * Produtos com billing_type=subscription também exigem Subscription ativa — sem isso o login
 * autentica e em seguida bloqueia com "não tem acesso".
 */
class MemberAccessGrantService
{
    /**
     * Liga o usuário ao produto e, se for assinatura, cria Subscription ativa se necessário.
     */
    public function grant(User $user, Product $product, ?SubscriptionPlan $plan = null): void
    {
        $user->products()->syncWithoutDetaching([(string) $product->id]);

        if (($product->billing_type ?? Product::BILLING_ONE_TIME) !== Product::BILLING_SUBSCRIPTION) {
            return;
        }

        $plan ??= $this->resolvePlan($product);
        if (! $plan) {
            return;
        }

        if ($this->hasValidActiveSubscription($user, $product)) {
            return;
        }

        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();

        $subscription = Subscription::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ]);

        event(new SubscriptionCreated($subscription));
    }

    /**
     * Remove o vínculo product_user e cancela assinaturas ativas desse produto.
     */
    public function revoke(User $user, Product $product): void
    {
        $user->products()->detach($product->id);

        Subscription::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->update(['status' => Subscription::STATUS_CANCELLED]);
    }

    /**
     * Preferência: plano vitalício; senão o primeiro por position/id.
     */
    public function resolvePlan(Product $product): ?SubscriptionPlan
    {
        $plans = $product->subscriptionPlans()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($plans->isEmpty()) {
            return null;
        }

        return $plans->first(fn (SubscriptionPlan $p) => $p->isLifetime())
            ?? $plans->first();
    }

    public function hasValidActiveSubscription(User $user, Product $product): bool
    {
        $today = now()->startOfDay()->toDateString();

        return Subscription::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(function ($q) use ($today) {
                $q->whereDate('current_period_end', '>=', $today)
                    ->orWhere(function ($q2) {
                        $q2->whereNull('current_period_end')
                            ->whereHas('subscriptionPlan', fn ($plan) => $plan->where('interval', SubscriptionPlan::INTERVAL_LIFETIME));
                    });
            })
            ->exists();
    }

    /**
     * Repara matrícula manual legada: product_user sem nenhuma Subscription em produto recorrente.
     * Não altera quem comprou e teve assinatura expirada (fluxo de renovação/checkout).
     */
    public function repairLegacyEnrollmentIfNeeded(User $user, Product $product): bool
    {
        if (($product->billing_type ?? Product::BILLING_ONE_TIME) !== Product::BILLING_SUBSCRIPTION) {
            return false;
        }

        if (! $product->users()->where('user_id', $user->id)->exists()) {
            return false;
        }

        if ($this->hasValidActiveSubscription($user, $product)) {
            return false;
        }

        $hasAnySubscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();

        if ($hasAnySubscription) {
            return false;
        }

        $this->grant($user, $product);

        return $this->hasValidActiveSubscription($user, $product);
    }

    /**
     * Valida acesso à área de membros, reparando matrículas legadas antes da checagem.
     */
    public function userHasMemberAreaAccess(User $user, Product $product): bool
    {
        $this->repairLegacyEnrollmentIfNeeded($user, $product);

        return $product->hasMemberAreaAccess($user);
    }
}
