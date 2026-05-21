<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\SendApiApplicationWebhookJob;
use App\Models\ApiApplication;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Webhook;
use App\Support\WebhookCustomerPayload;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookCustomerPayloadTest extends TestCase
{
    public function test_from_order_includes_cpf_and_phone_when_filled(): void
    {
        $user = User::factory()->create(['name' => 'Comprador Teste']);
        $product = $this->createTestProduct(['type' => Product::TYPE_LINK]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99.90,
            'email' => 'buyer@test.com',
            'cpf' => '12345678901',
            'phone' => '11987654321',
            'metadata' => ['customer_name' => 'Nome Metadata'],
        ]);

        $customer = WebhookCustomerPayload::fromOrder($order);

        $this->assertSame('Comprador Teste', $customer['name']);
        $this->assertSame('buyer@test.com', $customer['email']);
        $this->assertSame('12345678901', $customer['cpf']);
        $this->assertSame('11987654321', $customer['phone']);
    }

    public function test_from_order_omits_cpf_and_phone_when_empty(): void
    {
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => User::factory()->create()->id,
            'product_id' => $this->createTestProduct(['type' => Product::TYPE_LINK])->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'a@b.com',
            'cpf' => null,
            'phone' => null,
        ]);

        $customer = WebhookCustomerPayload::fromOrder($order);

        $this->assertArrayNotHasKey('cpf', $customer);
        $this->assertArrayNotHasKey('phone', $customer);
    }

    public function test_order_completed_integration_webhook_includes_customer_contact(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['type' => Product::TYPE_LINK, 'checkout_slug' => 'slug-wh']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'wh@test.com',
            'cpf' => '98765432100',
            'phone' => '21999998888',
        ]);

        Webhook::create([
            'tenant_id' => 1,
            'name' => 'CRM',
            'url' => 'https://example.com/hook',
            'events' => [OrderCompleted::class],
            'is_active' => true,
        ]);

        event(new OrderCompleted($order));

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $customer = $body['payload']['customer'] ?? [];

            return ($body['event'] ?? '') === 'pedido_pago'
                && ($customer['cpf'] ?? '') === '98765432100'
                && ($customer['phone'] ?? '') === '21999998888';
        });
    }

    public function test_api_application_webhook_includes_customer_when_filled(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();
        $app = ApiApplication::create([
            'tenant_id' => $tenant->tenant_id,
            'name' => 'App Test',
            'slug' => ApiApplication::generateUniqueSlug($tenant->tenant_id, 'App Test'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy_test'),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'webhook_url' => 'https://example.com/api-hook',
            'webhook_secret' => null,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->tenant_id,
            'user_id' => User::factory()->create()->id,
            'product_id' => $this->createTestProduct(['type' => Product::TYPE_LINK])->id,
            'api_application_id' => $app->id,
            'status' => 'completed',
            'amount' => 25,
            'email' => 'api@test.com',
            'cpf' => '11144477735',
            'phone' => '11911112222',
        ]);

        (new SendApiApplicationWebhookJob($order->id, 'order.completed'))->handle();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $customer = $body['customer'] ?? [];

            return ($body['event'] ?? '') === 'order.completed'
                && ($customer['cpf'] ?? '') === '11144477735'
                && ($customer['phone'] ?? '') === '11911112222';
        });
    }
}
