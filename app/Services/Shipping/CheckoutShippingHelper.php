<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

class CheckoutShippingHelper
{
    public function __construct(
        private readonly ShippingQuoteService $quoteService,
    ) {}

    public function productRequiresShipping(Product $product): bool
    {
        return $product->requiresShippingAddress();
    }

    /**
     * @return array<string, string>
     */
    public function shippingAddressValidationRules(): array
    {
        return [
            'shipping_cep' => ['required', 'string', 'max:9'],
            'shipping_street' => ['required', 'string', 'max:255'],
            'shipping_number' => ['required', 'string', 'max:32'],
            'shipping_complement' => ['nullable', 'string', 'max:120'],
            'shipping_neighborhood' => ['required', 'string', 'max:120'],
            'shipping_city' => ['required', 'string', 'max:120'],
            'shipping_state' => ['required', 'string', 'size:2'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{shipping_amount: float, shipping_store_id: ?int, shipping_rule_id: ?int, shipping_address: array<string, string>, metadata_shipping: array<string, mixed>}
     *
     * @throws \RuntimeException
     */
    public function resolveForCheckout(Product $product, array $validated): array
    {
        if (! $this->productRequiresShipping($product)) {
            return [
                'shipping_amount' => 0.0,
                'shipping_store_id' => null,
                'shipping_rule_id' => null,
                'shipping_address' => [],
                'metadata_shipping' => [],
            ];
        }

        if (strtoupper((string) ($validated['display_currency'] ?? 'BRL')) !== 'BRL') {
            throw new \RuntimeException('Produtos físicos estão disponíveis apenas em BRL.');
        }

        $cep = (string) ($validated['shipping_cep'] ?? $validated['address_zipcode'] ?? '');
        $quote = $this->quoteService->quote($product, $cep);

        $address = [
            'zip' => preg_replace('/\D/', '', $cep) ?? '',
            'street' => (string) ($validated['shipping_street'] ?? $validated['address_street'] ?? ''),
            'number' => (string) ($validated['shipping_number'] ?? $validated['address_number'] ?? ''),
            'complement' => (string) ($validated['shipping_complement'] ?? $validated['address_complement'] ?? ''),
            'neighborhood' => (string) ($validated['shipping_neighborhood'] ?? $validated['address_neighborhood'] ?? ''),
            'city' => (string) ($validated['shipping_city'] ?? $validated['address_city'] ?? ''),
            'state' => strtoupper((string) ($validated['shipping_state'] ?? $validated['address_state'] ?? '')),
        ];

        return [
            'shipping_amount' => $quote->shippingAmount,
            'shipping_store_id' => $quote->shippingStoreId,
            'shipping_rule_id' => $quote->shippingRuleId,
            'shipping_address' => $address,
            'metadata_shipping' => [
                'shipping_label' => $quote->ruleName,
                'delivery_days_min' => $quote->deliveryDaysMin,
                'delivery_days_max' => $quote->deliveryDaysMax,
                'free_shipping_product' => $quote->productFreeShipping,
                'free_shipping' => $quote->freeShipping,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function mergeShippingIntoOrderPayload(Product $product, array $validated, array $orderPayload): array
    {
        $shipping = $this->resolveForCheckout($product, $validated);
        $orderPayload['amount'] = round((float) $orderPayload['amount'] + $shipping['shipping_amount'], 2);
        $orderPayload['shipping_amount'] = $shipping['shipping_amount'];
        $orderPayload['shipping_store_id'] = $shipping['shipping_store_id'];
        $orderPayload['shipping_rule_id'] = $shipping['shipping_rule_id'];
        $orderPayload['shipping_address'] = $shipping['shipping_address'];

        $meta = is_array($orderPayload['metadata'] ?? null) ? $orderPayload['metadata'] : [];
        $orderPayload['metadata'] = array_merge($meta, $shipping['metadata_shipping']);

        return $orderPayload;
    }

    /**
     * @param  array<string, string>  $shippingAddress
     */
    public function shippingAddressForGateway(array $shippingAddress): array
    {
        return [
            'zip_code' => $shippingAddress['zip'] ?? '',
            'street_name' => $shippingAddress['street'] ?? '',
            'street_number' => $shippingAddress['number'] ?? '',
            'neighborhood' => $shippingAddress['neighborhood'] ?? '',
            'city' => $shippingAddress['city'] ?? '',
            'federal_unit' => $shippingAddress['state'] ?? '',
        ];
    }

    public function appendAddressRulesIfNeeded(Product $product, array $rules, string $displayCurrency = 'BRL'): array
    {
        if (! $this->productRequiresShipping($product)) {
            return $rules;
        }

        return array_merge($rules, $this->shippingAddressValidationRules());
    }
}
