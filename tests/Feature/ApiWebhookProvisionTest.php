<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\ApiScopes;
use Tests\TestCase;

class ApiWebhookProvisionTest extends TestCase
{
    /**
     * @return array{app: ApiApplication, public: string, secret: string}
     */
    private function createLegacyApiApp(int $tenantId): array
    {
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();

        $app = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'API App',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'Webhook API'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy-unused'),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
            'webhook_url' => null,
            'webhook_secret' => null,
            'webhook_events' => null,
            'webhook_enabled' => true,
        ]);

        return ['app' => $app, 'public' => $public, 'secret' => $secret];
    }

    /**
     * @return array{app: ApiApplication, public: string, secret: string, key: ApiKey}
     */
    private function createScopedApiApp(int $tenantId, array $scopes): array
    {
        $keys = $this->createLegacyApiApp($tenantId);
        $scopedSecret = ApiKey::generateSecretKey();
        $public = ApiKey::generatePublicKey();

        $key = ApiKey::create([
            'tenant_id' => $tenantId,
            'api_application_id' => $keys['app']->id,
            'name' => 'Partner key',
            'public_key' => $public,
            'secret_key_hash' => ApiKey::hashSecretKey($scopedSecret),
            'scopes' => $scopes,
            'allowed_ips' => [],
            'strict_idempotency' => true,
            'async_payments' => false,
            'rate_limit_tier' => 'standard',
            'is_active' => true,
        ]);

        return [
            'app' => $keys['app'],
            'public' => $public,
            'secret' => $scopedSecret,
            'key' => $key,
        ];
    }

    private function authHeaders(string $public, string $secret): array
    {
        return [
            'X-Public-Key' => $public,
            'X-Secret-Key' => $secret,
        ];
    }

    public function test_put_provisions_webhook_with_all_events_and_returns_secret_first_time(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);

        $response = $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', [
                'webhook_url' => 'https://partner.example/webhooks/getfy/1',
            ]);

        $response->assertOk();
        $response->assertJsonPath('webhook_url', 'https://partner.example/webhooks/getfy/1');
        $response->assertJsonPath('events_mode', 'all');
        $response->assertJsonPath('webhook_enabled', true);
        $response->assertJsonPath('has_secret', true);
        $this->assertNotEmpty($response->json('webhook_secret'));

        $keys['app']->refresh();
        $this->assertSame('https://partner.example/webhooks/getfy/1', $keys['app']->webhook_url);
        $this->assertNull($keys['app']->webhook_events);
        $this->assertSame($response->json('webhook_secret'), $keys['app']->webhook_secret);
    }

    public function test_put_same_url_again_does_not_return_secret(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);

        $url = 'https://partner.example/webhooks/getfy/1';
        $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', ['webhook_url' => $url])
            ->assertOk();

        $response = $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', ['webhook_url' => $url]);

        $response->assertOk();
        $response->assertJsonPath('has_secret', true);
        $this->assertNull($response->json('webhook_secret'));
    }

    public function test_put_with_rotate_secret_returns_new_secret(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);

        $url = 'https://partner.example/webhooks/getfy/1';
        $first = $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', ['webhook_url' => $url])
            ->assertOk()
            ->json('webhook_secret');

        $response = $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', [
                'webhook_url' => $url,
                'rotate_secret' => true,
            ]);

        $response->assertOk();
        $newSecret = $response->json('webhook_secret');
        $this->assertNotEmpty($newSecret);
        $this->assertNotSame($first, $newSecret);
    }

    public function test_put_without_webhooks_write_scope_returns_403(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createScopedApiApp((int) $seller->id, [ApiScopes::PAYMENTS_WRITE]);

        $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', [
                'webhook_url' => 'https://partner.example/webhooks/getfy/1',
            ])
            ->assertForbidden();
    }

    public function test_put_with_http_url_returns_422(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);

        $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', [
                'webhook_url' => 'http://partner.example/webhooks/getfy/1',
            ])
            ->assertUnprocessable();
    }

    public function test_get_returns_config_without_secret(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);

        $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', [
                'webhook_url' => 'https://partner.example/webhooks/getfy/1',
            ])
            ->assertOk();

        $response = $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->getJson('/api/v1/webhook');

        $response->assertOk();
        $response->assertJsonPath('webhook_url', 'https://partner.example/webhooks/getfy/1');
        $response->assertJsonPath('has_secret', true);
        $this->assertNull($response->json('webhook_secret'));
    }

    public function test_post_rotate_secret_returns_new_secret(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);

        $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', [
                'webhook_url' => 'https://partner.example/webhooks/getfy/1',
            ])
            ->assertOk();

        $response = $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->postJson('/api/v1/webhook/rotate-secret');

        $response->assertOk();
        $this->assertNotEmpty($response->json('webhook_secret'));
    }

    public function test_put_clears_partial_webhook_events_to_all(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);
        $keys['app']->update(['webhook_events' => ['order.completed', 'pix.generated']]);

        $url = 'https://partner.example/webhooks/getfy/1';
        $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', ['webhook_url' => $url])
            ->assertOk()
            ->assertJsonPath('events_mode', 'all')
            ->assertJsonPath('webhook_events', null);

        $keys['app']->refresh();
        $this->assertNull($keys['app']->webhook_events);
    }

    public function test_scoped_key_with_webhooks_write_can_provision(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createScopedApiApp((int) $seller->id, [ApiScopes::WEBHOOKS_WRITE]);

        $this->withHeaders($this->authHeaders($keys['public'], $keys['secret']))
            ->putJson('/api/v1/webhook', [
                'webhook_url' => 'https://partner.example/webhooks/getfy/2',
            ])
            ->assertOk()
            ->assertJsonPath('events_mode', 'all');
    }
}
