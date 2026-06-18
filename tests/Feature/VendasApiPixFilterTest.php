<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\Order;
use App\Models\User;
use App\Support\ApiScopes;
use Tests\TestCase;

class VendasApiPixFilterTest extends TestCase
{
    public function test_sale_channel_api_pix_filters_orders(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $tenantId = (int) $seller->id;

        $product = $this->createTestProduct(['tenant_id' => $tenantId]);
        $apiApp = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'API PIX',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'API PIX'),
            'api_key_hash' => hash('sha256', 'key'),
            'public_key' => ApiApplication::generatePublicKey(),
            'secret_key_hash' => hash('sha256', 'sec'),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
        ]);

        $checkoutOrder = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'c@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);

        $apiOrder = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'api_application_id' => $apiApp->id,
            'status' => 'completed',
            'amount' => 20,
            'email' => 'a@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'metadata' => ['source' => 'api'],
        ]);

        $this->actingAs($seller)
            ->get(route('vendas.index', ['sale_channel' => 'api_pix']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendas/Index')
                ->has('vendas.data', 1)
                ->where('vendas.data.0.id', $apiOrder->id)
            );

        $this->actingAs($seller)
            ->get(route('vendas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vendas.data', 2)
            );
    }
}
