<?php

namespace Tests\Feature\Shipping;

use App\Models\Product;
use App\Models\ShippingRule;
use App\Models\ShippingStore;
use App\Services\Shipping\ShippingQuoteService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingQuoteServiceTest extends TestCase
{
    private function createStoreWithRule(array $ruleOverrides = []): ShippingStore
    {
        $store = ShippingStore::create([
            'tenant_id' => 1,
            'name' => 'Loja teste',
            'is_active' => true,
            'origin_zip' => '01310100',
            'origin_street' => 'Av Paulista',
            'origin_number' => '1000',
            'origin_neighborhood' => 'Bela Vista',
            'origin_city' => 'São Paulo',
            'origin_state' => 'SP',
        ]);

        ShippingRule::create(array_merge([
            'shipping_store_id' => $store->id,
            'priority' => 10,
            'name' => 'Todo Brasil',
            'is_active' => true,
            'match_type' => ShippingRule::MATCH_ALL,
            'match_config' => [],
            'price' => 19.90,
            'is_free' => false,
            'delivery_days_min' => 3,
            'delivery_days_max' => 7,
        ], $ruleOverrides));

        return $store;
    }

    public function test_free_shipping_on_product_returns_zero(): void
    {
        $store = $this->createStoreWithRule();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_PRODUTO_FISICO,
            'shipping_store_id' => $store->id,
            'physical_config' => ['free_shipping' => true],
        ]);

        $quote = app(ShippingQuoteService::class)->quote($product, '01310100');

        $this->assertSame(0.0, $quote->shippingAmount);
        $this->assertTrue($quote->freeShipping);
        $this->assertTrue($quote->productFreeShipping);
    }

    public function test_matching_rule_returns_price(): void
    {
        Http::fake([
            'viacep.com.br/ws/*' => Http::response([
                'uf' => 'SP',
                'localidade' => 'São Paulo',
                'logradouro' => 'Av Paulista',
                'bairro' => 'Bela Vista',
            ]),
        ]);

        $store = $this->createStoreWithRule();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_PRODUTO_FISICO,
            'shipping_store_id' => $store->id,
            'price' => 50,
        ]);

        $quote = app(ShippingQuoteService::class)->quote($product, '01310-100');

        $this->assertSame(19.9, $quote->shippingAmount);
        $this->assertSame('Todo Brasil', $quote->ruleName);
        $this->assertSame(3, $quote->deliveryDaysMin);
    }

    public function test_no_matching_rule_throws(): void
    {
        Http::fake([
            'viacep.com.br/ws/*' => Http::response([
                'uf' => 'PR',
                'localidade' => 'Curitiba',
            ]),
        ]);

        $store = $this->createStoreWithRule([
            'match_type' => ShippingRule::MATCH_STATE,
            'match_config' => ['states' => ['SP']],
            'name' => 'Só SP',
        ]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_PRODUTO_FISICO,
            'shipping_store_id' => $store->id,
        ]);

        $this->expectException(\RuntimeException::class);
        app(ShippingQuoteService::class)->quote($product, '80010000');
    }
}
