<?php

namespace Tests\Feature;

use App\Gateways\Pagarme\PagarmeDriver;
use App\Http\Middleware\EnsureInstalled;
use App\Models\CheckoutSession;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CardInstallmentsProductCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function approvedSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function connectGateway(string $slug, array $credentials, ?int $tenantId = null): void
    {
        $cred = new GatewayCredential([
            'tenant_id' => $tenantId,
            'gateway_slug' => $slug,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $cred->setEncryptedCredentials($credentials);
        $cred->save();
    }

    private function setCardOrder(array $slugs): void
    {
        Setting::set('gateway_order', [
            'pix' => [],
            'card' => $slugs,
            'boleto' => [],
            'pix_auto' => [],
        ], null);
    }

    public function test_product_edit_shows_installments_when_pagarme_is_connected_card_gateway(): void
    {
        $this->setCardOrder(['cajupay', 'pagarme']);
        $this->connectGateway('pagarme', [
            'secret_key' => 'sk_test',
            'public_key' => 'pk_test',
        ]);

        $seller = $this->approvedSeller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 97.90,
        ]);

        $this->actingAs($seller)
            ->get(route('produtos.edit', $product->id).'?tab=configuracoes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Produtos/Edit')
                ->where('checkout_gateway_ui.card_show_installments', true)
                ->where('checkout_gateway_ui.card_installments_gateway_name', 'Pagar.me'));
    }

    public function test_product_edit_hides_installments_when_only_cajupay_card_is_connected(): void
    {
        $this->setCardOrder(['cajupay', 'pagarme']);
        $this->connectGateway('cajupay', [
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);

        $seller = $this->approvedSeller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 97.90,
        ]);

        $this->actingAs($seller)
            ->get(route('produtos.edit', $product->id).'?tab=configuracoes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Produtos/Edit')
                ->where('checkout_gateway_ui.card_show_installments', false));
    }

    public function test_subscription_product_save_forces_installments_off(): void
    {
        $seller = $this->approvedSeller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 49.90,
            'checkout_config' => [
                'card_installments' => ['enabled' => true, 'max' => 12],
            ],
        ]);

        $this->actingAs($seller)->put(route('produtos.update', $product->id), [
            'name' => $product->name,
            'description' => $product->description,
            'type' => $product->type,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 49.90,
            'currency' => 'BRL',
            'is_active' => true,
            'base_interval' => 'monthly',
            'card_installments' => ['enabled' => '1', 'max' => 12],
        ])->assertRedirect();

        $product->refresh();
        $this->assertFalse((bool) ($product->checkout_config['card_installments']['enabled'] ?? true));
        $this->assertSame(1, (int) ($product->checkout_config['card_installments']['max'] ?? 0));
    }

    public function test_checkout_show_exposes_installments_and_subscription_forces_one(): void
    {
        $this->setCardOrder(['pagarme']);
        $this->connectGateway('pagarme', [
            'secret_key' => 'sk_test',
            'public_key' => 'pk_test',
        ]);

        $oneTime = $this->checkoutProduct([
            'price' => 97.90,
            'checkout_config' => [
                'card_installments' => ['enabled' => true, 'max' => 6],
            ],
        ]);

        $this->get('/c/'.$oneTime->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Show')
                ->where('card_installments_enabled', true)
                ->where('card_max_installments', 6));

        $subscription = $this->checkoutProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 49.90,
            'checkout_config' => [
                'card_installments' => ['enabled' => true, 'max' => 12],
            ],
        ]);
        $this->createMonthlyPlan($subscription);

        $this->get('/c/'.$subscription->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Show')
                ->where('card_installments_enabled', false)
                ->where('card_max_installments', 1));
    }

    public function test_checkout_sends_clamped_installments_to_pagarme_driver(): void
    {
        Event::fake();
        $this->setCardOrder(['pagarme']);
        $this->connectGateway('pagarme', [
            'secret_key' => 'sk_test',
            'public_key' => 'pk_test',
        ]);

        $product = $this->checkoutProduct([
            'price' => 97.90,
            'checkout_config' => [
                'card_installments' => ['enabled' => true, 'max' => 6],
            ],
        ]);

        $captured = null;
        $driver = Mockery::mock(PagarmeDriver::class);
        $driver->shouldReceive('createCardPayment')
            ->once()
            ->andReturnUsing(function ($credentials, $amount, $consumer, $externalId, array $card) use (&$captured) {
                $captured = $card;

                return ['transaction_id' => 'ch_test_3x', 'status' => 'pending'];
            });
        $this->app->instance(PagarmeDriver::class, $driver);

        $session = $this->checkoutSession($product);
        $response = $this->postJson('/checkout', $this->cardPayload($session, $product, [
            'installments' => 3,
        ]));

        $response->assertOk();
        $this->assertNotNull($captured);
        $this->assertSame(3, $captured['installments']);
        $token = json_decode((string) $captured['payment_token'], true);
        $this->assertSame(3, $token['installments']);

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(3, (int) ($order->metadata['installments'] ?? 0));
    }

    public function test_checkout_forces_one_installment_when_disabled(): void
    {
        Event::fake();
        $this->setCardOrder(['pagarme']);
        $this->connectGateway('pagarme', [
            'secret_key' => 'sk_test',
            'public_key' => 'pk_test',
        ]);

        $product = $this->checkoutProduct([
            'price' => 97.90,
            'checkout_config' => [
                'card_installments' => ['enabled' => false, 'max' => 12],
            ],
        ]);

        $captured = null;
        $driver = Mockery::mock(PagarmeDriver::class);
        $driver->shouldReceive('createCardPayment')
            ->once()
            ->andReturnUsing(function ($credentials, $amount, $consumer, $externalId, array $card) use (&$captured) {
                $captured = $card;

                return ['transaction_id' => 'ch_test_1x', 'status' => 'pending'];
            });
        $this->app->instance(PagarmeDriver::class, $driver);

        $session = $this->checkoutSession($product);
        $this->postJson('/checkout', $this->cardPayload($session, $product, [
            'installments' => 6,
        ]))->assertOk();

        $this->assertSame(1, $captured['installments']);
        $order = Order::query()->latest('id')->first();
        $this->assertSame(1, (int) ($order->metadata['installments'] ?? 0));
    }

    public function test_checkout_clamps_installments_when_amount_is_low(): void
    {
        Event::fake();
        $this->setCardOrder(['pagarme']);
        $this->connectGateway('pagarme', [
            'secret_key' => 'sk_test',
            'public_key' => 'pk_test',
        ]);

        $product = $this->checkoutProduct([
            'price' => 8,
            'checkout_config' => [
                'card_installments' => ['enabled' => true, 'max' => 12],
            ],
        ]);

        $captured = null;
        $driver = Mockery::mock(PagarmeDriver::class);
        $driver->shouldReceive('createCardPayment')
            ->once()
            ->andReturnUsing(function ($credentials, $amount, $consumer, $externalId, array $card) use (&$captured) {
                $captured = $card;

                return ['transaction_id' => 'ch_test_low', 'status' => 'pending'];
            });
        $this->app->instance(PagarmeDriver::class, $driver);

        $session = $this->checkoutSession($product);
        $this->postJson('/checkout', $this->cardPayload($session, $product, [
            'installments' => 5,
        ]))->assertOk();

        $this->assertSame(1, $captured['installments']);
    }

    public function test_subscription_checkout_ignores_requested_installments(): void
    {
        Event::fake();
        $this->setCardOrder(['pagarme']);
        $this->connectGateway('pagarme', [
            'secret_key' => 'sk_test',
            'public_key' => 'pk_test',
        ]);

        $product = $this->checkoutProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 49.90,
            'checkout_config' => [
                'card_installments' => ['enabled' => true, 'max' => 12],
            ],
        ]);
        $plan = $this->createMonthlyPlan($product);

        $captured = null;
        $driver = Mockery::mock(PagarmeDriver::class);
        $driver->shouldReceive('createCardPayment')
            ->once()
            ->andReturnUsing(function ($credentials, $amount, $consumer, $externalId, array $card) use (&$captured) {
                $captured = $card;

                return ['transaction_id' => 'ch_test_sub', 'status' => 'pending'];
            });
        $this->app->instance(PagarmeDriver::class, $driver);

        $session = $this->checkoutSession($product);
        $this->postJson('/checkout', $this->cardPayload($session, $product, [
            'installments' => 6,
            'subscription_plan_id' => $plan->id,
        ]))->assertOk();

        $this->assertSame(1, $captured['installments']);
        $order = Order::query()->latest('id')->first();
        $this->assertSame(1, (int) ($order->metadata['installments'] ?? 0));
    }

    private function checkoutProduct(array $overrides = []): Product
    {
        return $this->createTestProduct(array_merge([
            'checkout_slug' => strtolower(Str::random(7)),
        ], $overrides));
    }

    private function createMonthlyPlan(Product $product, float $price = 49.90): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => $price,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(),
            'position' => 0,
        ]);
    }

    private function checkoutSession(Product $product): CheckoutSession
    {
        $session = CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug ?: 'chkinst',
            'session_token' => (string) Str::uuid(),
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => '127.0.0.1',
        ]);
        CheckoutSession::query()->whereKey($session->id)->update([
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        return $session->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cardPayload(CheckoutSession $session, Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'payment_method' => 'card',
            'email' => 'buyer-installments@example.com',
            'name' => 'Cliente Parcelas',
            'cpf' => '52998224725',
            'phone' => '11999999999',
            'checkout_session_token' => $session->session_token,
            'website' => '',
            'payment_token' => json_encode(['card_token' => 'tok_checkout', 'installments' => 12]),
            'installments' => 1,
            'address_zipcode' => '01310100',
            'address_street' => 'Avenida Paulista',
            'address_number' => '1000',
            'address_neighborhood' => 'Bela Vista',
            'address_city' => 'Sao Paulo',
            'address_state' => 'SP',
        ], $overrides);
    }
}
