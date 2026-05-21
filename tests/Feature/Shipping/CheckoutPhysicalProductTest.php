<?php

namespace Tests\Feature\Shipping;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingRule;
use App\Models\ShippingStore;
use App\Models\User;
use App\Services\PhysicalProductAccess;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutPhysicalProductTest extends TestCase
{
    private function physicalProductSetup(): array
    {
        Setting::set(PhysicalProductAccess::SETTING_KEY, '1', null);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $store = ShippingStore::create([
            'tenant_id' => 1,
            'name' => 'Loja',
            'is_active' => true,
            'origin_zip' => '01310100',
            'origin_street' => 'Rua A',
            'origin_number' => '1',
            'origin_neighborhood' => 'Centro',
            'origin_city' => 'São Paulo',
            'origin_state' => 'SP',
        ]);
        ShippingRule::create([
            'shipping_store_id' => $store->id,
            'priority' => 10,
            'name' => 'Padrão',
            'is_active' => true,
            'match_type' => ShippingRule::MATCH_ALL,
            'match_config' => [],
            'price' => 10,
            'is_free' => false,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Físico',
            'price' => 100,
            'checkout_slug' => 'fisico1',
            'type' => Product::TYPE_PRODUTO_FISICO,
            'shipping_store_id' => $store->id,
            'checkout_config' => [
                'customer_fields' => [
                    'name' => true,
                    'cpf' => false,
                    'phone' => false,
                    'coupon' => false,
                ],
                'payment_methods' => ['pix' => true],
            ],
        ]);

        Http::fake([
            'viacep.com.br/ws/*' => Http::response([
                'uf' => 'SP',
                'localidade' => 'São Paulo',
                'logradouro' => 'Av Paulista',
                'bairro' => 'Bela Vista',
            ]),
        ]);

        return [$product, $store];
    }

    public function test_shipping_quote_includes_freight_in_total(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
        [$product] = $this->physicalProductSetup();

        $response = $this->postJson('/checkout/shipping-quote', [
            'product_id' => $product->id,
            'cep' => '01310100',
        ]);

        $response->assertOk()
            ->assertJsonPath('shipping_amount', 10)
            ->assertJsonPath('total_with_shipping', 110);
    }

    public function test_checkout_show_exposes_requires_shipping(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);
        [$product] = $this->physicalProductSetup();

        $this->get('/c/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Show')
                ->where('product.requires_shipping', true)
                ->where('product.free_shipping', false));
    }
}
