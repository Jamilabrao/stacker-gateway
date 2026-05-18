<?php

namespace App\Services\Shipping;

class ShippingQuoteResult
{
    public function __construct(
        public float $shippingAmount,
        public bool $freeShipping,
        public ?int $shippingRuleId = null,
        public ?string $ruleName = null,
        public ?int $shippingStoreId = null,
        public ?int $deliveryDaysMin = null,
        public ?int $deliveryDaysMax = null,
        public bool $productFreeShipping = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shipping_amount' => round($this->shippingAmount, 2),
            'free_shipping' => $this->freeShipping,
            'shipping_rule_id' => $this->shippingRuleId,
            'rule_name' => $this->ruleName,
            'shipping_store_id' => $this->shippingStoreId,
            'delivery_days_min' => $this->deliveryDaysMin,
            'delivery_days_max' => $this->deliveryDaysMax,
            'product_free_shipping' => $this->productFreeShipping,
        ];
    }
}
