<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\InboundGatewayWebhook;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class InboundGatewayWebhooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_pagarme_webhook_is_logged_even_when_order_is_missing(): void
    {
        $payload = [
            'type' => 'charge.paid',
            'data' => [
                'id' => 'ch_test_inbound_1',
                'status' => 'paid',
            ],
        ];

        $this->postJson('/webhooks/gateways/pagarme', $payload)
            ->assertOk()
            ->assertJson(['received' => true]);

        $row = InboundGatewayWebhook::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('pagarme', $row->gateway_slug);
        $this->assertSame('charge.paid', $row->event);
        $this->assertSame('ch_test_inbound_1', $row->transaction_id);
        $this->assertSame('/webhooks/gateways/pagarme', $row->path);
        $this->assertSame(200, $row->http_status);
    }

    public function test_platform_admin_can_list_and_filter_inbound_webhooks(): void
    {
        InboundGatewayWebhook::query()->create([
            'gateway_slug' => 'pagarme',
            'http_method' => 'POST',
            'path' => '/webhooks/gateways/pagarme',
            'event' => 'charge.paid',
            'transaction_id' => 'ch_aaa',
            'http_status' => 200,
            'payload' => ['type' => 'charge.paid'],
        ]);
        InboundGatewayWebhook::query()->create([
            'gateway_slug' => 'mercadopago',
            'http_method' => 'POST',
            'path' => '/webhooks/gateways/mercadopago',
            'event' => 'payment',
            'transaction_id' => '123',
            'http_status' => 200,
            'payload' => ['action' => 'payment.updated'],
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/webhooks?gateway=pagarme&per_page=25')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Webhooks/Index')
                ->where('filters.gateway', 'pagarme')
                ->where('filters.per_page', 25)
                ->has('webhooks.data', 1)
                ->where('webhooks.data.0.gateway_slug', 'pagarme')
            );
    }

    public function test_per_page_is_clamped_to_allowed_values(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/webhooks?per_page=999')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.per_page', 25));
    }
}
