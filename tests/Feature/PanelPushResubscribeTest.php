<?php

namespace Tests\Feature;

use App\Models\PanelPushSubscription;
use App\Models\User;
use App\Support\PanelPushSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestVapidKeys;
use Tests\TestCase;

class PanelPushResubscribeTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestVapidKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPushFeatureTests();
    }

    public function test_push_subscribe_stores_vapid_public_key(): void
    {
        $keys = $this->configureTestVapidPush();

        $user = $this->createSellerUser();

        $this->actingAs($user)->postJson('/painel/push-subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-abc',
            'keys' => [
                'auth' => 'dGVzdA',
                'p256dh' => 'dGVzdA',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('panel_push_subscriptions', [
            'user_id' => $user->id,
            'vapid_public_key' => $keys['publicKey'],
        ]);
    }

    public function test_notifications_api_reports_needs_resubscribe_when_vapid_key_mismatch(): void
    {
        $this->configureTestVapidPush();

        $user = $this->createSellerUser();

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'vapid_public_key' => 'OLD_KEY_MISMATCH',
            'endpoint' => 'https://push.example/sub/1',
            'keys' => ['auth' => 'authkey', 'p256dh' => 'p256dhkey'],
        ]);

        $this->actingAs($user)
            ->getJson('/painel/notifications')
            ->assertOk()
            ->assertJsonPath('push_subscribed', false)
            ->assertJsonPath('push_needs_resubscribe', true);
    }

    public function test_notifications_api_reports_subscribed_when_vapid_key_matches(): void
    {
        $keys = $this->configureTestVapidPush();

        $user = $this->createSellerUser();

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'vapid_public_key' => $keys['publicKey'],
            'endpoint' => 'https://push.example/sub/1',
            'keys' => ['auth' => 'authkey', 'p256dh' => 'p256dhkey'],
        ]);

        $this->actingAs($user)
            ->getJson('/painel/notifications')
            ->assertOk()
            ->assertJsonPath('push_subscribed', true)
            ->assertJsonPath('push_needs_resubscribe', false);
    }

    public function test_push_data_reports_stale_subscriptions_count(): void
    {
        $this->configureTestVapidPush();

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        PanelPushSubscription::create([
            'user_id' => User::factory()->create(['tenant_id' => 1])->id,
            'tenant_id' => 1,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'vapid_public_key' => 'stale-key',
            'endpoint' => 'https://push.example/sub/1',
            'keys' => ['auth' => 'a', 'p256dh' => 'b'],
        ]);

        $this->actingAs($admin)
            ->getJson(route('plataforma.app.push.data'))
            ->assertOk()
            ->assertJsonPath('stale_subscriptions_count', 1);
    }
}
