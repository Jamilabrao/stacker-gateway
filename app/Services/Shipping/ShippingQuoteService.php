<?php

namespace App\Services\Shipping;

use App\Models\Product;
use App\Models\ShippingRule;
use App\Models\ShippingStore;
use Illuminate\Support\Collection;

class ShippingQuoteService
{
    public function __construct(
        private readonly ViaCepResolver $viaCep,
        private readonly ShippingRuleMatcher $matcher,
    ) {}

    /**
     * @throws \RuntimeException
     */
    public function quote(Product $product, string $destinationCep, ?int $storeIdOverride = null): ShippingQuoteResult
    {
        if (! $product->isPhysical()) {
            return new ShippingQuoteResult(
                shippingAmount: 0,
                freeShipping: true,
            );
        }

        if ($product->hasFreeShipping()) {
            $storeId = $storeIdOverride ?? $product->shipping_store_id;

            return new ShippingQuoteResult(
                shippingAmount: 0,
                freeShipping: true,
                shippingStoreId: $storeId ? (int) $storeId : null,
                productFreeShipping: true,
            );
        }

        $store = $this->resolveStore($product, $storeIdOverride);
        if ($store === null) {
            throw new \RuntimeException('Frete não configurado para este produto. Vincule uma loja em Produtos → Frete.');
        }

        $cepDigits = preg_replace('/\D/', '', $destinationCep) ?? '';
        if (strlen($cepDigits) !== 8) {
            throw new \RuntimeException('Informe um CEP válido com 8 dígitos.');
        }

        $destination = $this->viaCep->resolve($cepDigits);
        $rules = $store->activeRules()->orderBy('priority')->orderBy('id')->get();

        foreach ($rules as $rule) {
            if ($this->matcher->matches($rule, $cepDigits, $destination)) {
                return $this->resultFromRule($rule, $store);
            }
        }

        throw new \RuntimeException('Não há frete disponível para este CEP. Ajuste as regras da loja em Taxas e frete.');
    }

    private function resolveStore(Product $product, ?int $storeIdOverride): ?ShippingStore
    {
        $storeId = $storeIdOverride ?? $product->shipping_store_id;
        if ($storeId === null) {
            return null;
        }

        return ShippingStore::query()
            ->where('id', $storeId)
            ->where('tenant_id', $product->tenant_id)
            ->where('is_active', true)
            ->first();
    }

    private function resultFromRule(ShippingRule $rule, ShippingStore $store): ShippingQuoteResult
    {
        $amount = $rule->is_free ? 0.0 : (float) $rule->price;

        return new ShippingQuoteResult(
            shippingAmount: round($amount, 2),
            freeShipping: $rule->is_free,
            shippingRuleId: $rule->id,
            ruleName: $rule->name ?: $this->defaultRuleLabel($rule),
            shippingStoreId: $store->id,
            deliveryDaysMin: $rule->delivery_days_min,
            deliveryDaysMax: $rule->delivery_days_max,
        );
    }

    private function defaultRuleLabel(ShippingRule $rule): string
    {
        return match ($rule->match_type) {
            ShippingRule::MATCH_ALL => 'Todo o Brasil',
            ShippingRule::MATCH_STATE => 'Por estado',
            ShippingRule::MATCH_CITY => 'Por cidade',
            ShippingRule::MATCH_CEP_RANGE => 'Faixa de CEP',
            ShippingRule::MATCH_CEP_PREFIX => 'Prefixo de CEP',
            default => 'Regra de frete',
        };
    }

    /**
     * @param  Collection<int, ShippingStore>  $stores
     */
    public function storeHasActiveRules(ShippingStore $store): bool
    {
        return $store->activeRules()->exists();
    }
}
