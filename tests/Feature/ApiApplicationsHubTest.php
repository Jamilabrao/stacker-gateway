<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureSellerPanel;
use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Api\ApiWebhookDeliveryService;
use App\Support\ApiScopes;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class ApiApplicationsHubTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureSellerPanel::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function seller(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        return $seller->fresh();
    }

    private function application(User $seller): ApiApplication
    {
        $secret = ApiApplication::generateSecretKey();

        return ApiApplication::create([
            'tenant_id' => $seller->tenant_id,
            'name' => 'Integração API PIX',
            'slug' => ApiApplication::SLUG_GLOBAL_PIX_API,
            'api_key_hash' => ApiApplication::hashApiKey('legacy-key'),
            'public_key' => ApiApplication::generatePublicKey(),
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'secret_encrypted' => ApiApplication::encryptSecretForStorage($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'webhook_url' => 'https://example.com/hook',
            'webhook_secret' => 'old-secret',
            'webhook_events' => ['order.completed'],
            'webhook_enabled' => true,
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
        ]);
    }

    public function test_index_returns_hub_payload(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);

        $response = $this->actingAs($seller)->get('/aplicacoes-api');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ApiApplications/Index')
            ->has('application')
            ->has('keys', 1)
            ->has('webhook')
            ->has('webhook_event_catalog')
            ->has('available_scopes')
            ->has('scope_catalog')
            ->where('application.id', $app->id)
            ->where('keys.0.type', 'legacy')
        );
    }

    public function test_edit_redirects_to_index(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);

        $this->actingAs($seller)
            ->get("/aplicacoes-api/{$app->id}/edit")
            ->assertRedirect(route('api-applications.index'));
    }

    public function test_store_api_key_with_secret_encrypted(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);

        $this->actingAs($seller)
            ->post("/aplicacoes-api/{$app->id}/keys", [
                'name' => 'staging',
                'scopes' => ['payments:read', 'payments:write'],
            ])
            ->assertRedirect(route('api-applications.index'))
            ->assertSessionHas('api_key_reveal');

        $key = ApiKey::query()->where('api_application_id', $app->id)->first();
        $this->assertNotNull($key);
        $this->assertTrue($key->canRevealSecret());
    }

    public function test_update_api_key(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);
        $secret = ApiKey::generateSecretKey();
        $key = ApiKey::create([
            'tenant_id' => $seller->tenant_id,
            'api_application_id' => $app->id,
            'name' => 'dev',
            'public_key' => ApiKey::generatePublicKey(),
            'secret_key_hash' => ApiKey::hashSecretKey($secret),
            'secret_encrypted' => ApiKey::encryptSecretForStorage($secret),
            'scopes' => ['payments:read'],
            'allowed_ips' => [],
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->put("/aplicacoes-api/{$app->id}/keys/{$key->id}", [
                'name' => 'produção',
                'scopes' => ['payments:read', 'withdrawals:read'],
                'is_active' => false,
            ])
            ->assertRedirect(route('api-applications.index'));

        $key->refresh();
        $this->assertSame('produção', $key->name);
        $this->assertFalse($key->is_active);
        $this->assertContains('withdrawals:read', $key->scopes);
    }

    public function test_reveal_api_key_secret(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);
        $plain = ApiKey::generateSecretKey();
        $key = ApiKey::create([
            'tenant_id' => $seller->tenant_id,
            'api_application_id' => $app->id,
            'name' => 'dev',
            'public_key' => ApiKey::generatePublicKey(),
            'secret_key_hash' => ApiKey::hashSecretKey($plain),
            'secret_encrypted' => ApiKey::encryptSecretForStorage($plain),
            'scopes' => ['payments:read'],
            'allowed_ips' => [],
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->postJson("/aplicacoes-api/{$app->id}/keys/{$key->id}/reveal-secret")
            ->assertOk()
            ->assertJson(['secret_key' => $plain]);
    }

    public function test_patch_webhook_saves_url_and_events(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);

        $this->actingAs($seller)
            ->patch("/aplicacoes-api/{$app->id}/webhook", [
                'webhook_url' => 'https://partner.example/webhook',
                'webhook_events' => ['order.completed', 'pix.generated'],
                'webhook_enabled' => true,
            ])
            ->assertRedirect(route('api-applications.index', ['tab' => 'webhooks']));

        $app->refresh();
        $this->assertSame('https://partner.example/webhook', $app->webhook_url);
        $this->assertSame(['order.completed', 'pix.generated'], $app->webhook_events);
    }

    public function test_rotate_webhook_secret_returns_new_secret(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);

        $response = $this->actingAs($seller)
            ->postJson("/aplicacoes-api/{$app->id}/webhook/rotate-secret");

        $response->assertOk();
        $newSecret = $response->json('webhook_secret');
        $this->assertIsString($newSecret);
        $this->assertNotSame('old-secret', $newSecret);
        $this->assertSame($newSecret, $app->fresh()->webhook_secret);
    }

    public function test_webhook_delivery_skips_unsubscribed_event(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);
        $app->update(['webhook_events' => ['order.completed']]);

        $service = app(ApiWebhookDeliveryService::class);
        $allowed = $service->dispatch($app, 'order.completed', ['order_id' => '1']);
        $blocked = $service->dispatch($app, 'pix.generated', ['order_id' => '2']);

        $this->assertNotNull($allowed);
        $this->assertNull($blocked);
    }

    public function test_webhook_delivery_skipped_when_paused(): void
    {
        $seller = $this->seller();
        $app = $this->application($seller);
        $app->update(['webhook_enabled' => false]);

        $service = app(ApiWebhookDeliveryService::class);
        $delivery = $service->dispatch($app, 'order.completed', ['order_id' => '1']);

        $this->assertNull($delivery);
    }
}
