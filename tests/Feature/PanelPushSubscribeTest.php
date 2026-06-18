<?php

namespace Tests\Feature;

use App\Models\PanelPushSubscription;
use App\Models\User;
use App\Support\PanelPushSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestVapidKeys;
use Tests\TestCase;

class PanelPushSubscribeTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestVapidKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPushFeatureTests();
    }

    public function test_vapid_push_subscribe_stores_subscription(): void
    {
        $keys = $this->configureTestVapidPush();

        $user = $this->createSellerUser();

        $response = $this->actingAs($user)->postJson('/painel/push-subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-abc',
            'keys' => [
                'auth' => 'dGVzdA',
                'p256dh' => 'dGVzdA',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('vapid_public_key', $keys['publicKey']);
        $this->assertDatabaseHas('panel_push_subscriptions', [
            'user_id' => $user->id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'vapid_public_key' => $keys['publicKey'],
        ]);
    }

    public function test_vapid_resubscribe_replaces_previous_endpoint_for_same_user(): void
    {
        $this->configureTestVapidPush();
        $user = $this->createSellerUser();

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://push.example.com/old-endpoint',
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
        ]);

        $this->actingAs($user)->postJson('/painel/push-subscribe', [
            'endpoint' => 'https://push.example.com/new-endpoint',
            'keys' => [
                'auth' => 'dGVzdB',
                'p256dh' => 'dGVzdB',
            ],
        ])->assertOk();

        $this->assertSame(
            1,
            PanelPushSubscription::query()
                ->where('user_id', $user->id)
                ->where('provider', PanelPushSubscription::PROVIDER_VAPID)
                ->count()
        );
        $this->assertDatabaseHas('panel_push_subscriptions', [
            'user_id' => $user->id,
            'endpoint' => 'https://push.example.com/new-endpoint',
        ]);
        $this->assertDatabaseMissing('panel_push_subscriptions', [
            'endpoint' => 'https://push.example.com/old-endpoint',
        ]);
    }

    public function test_fcm_push_subscribe_stores_token(): void
    {
        config(['getfy.pwa.push_provider' => PanelPushSettings::PROVIDER_FCM]);
        PanelPushSettings::saveGlobal([
            'push_provider' => PanelPushSettings::PROVIDER_FCM,
            'firebase_project_id' => 'proj-test',
            'firebase_api_key' => 'api-key',
            'firebase_messaging_sender_id' => '123',
            'firebase_app_id' => 'app-id',
            'firebase_web_vapid_key' => 'web-vapid',
        ]);

        $row = \App\Models\BrandingSetting::query()->whereNull('tenant_id')->first();
        $data = is_array($row->data) ? $row->data : [];
        $data['firebase_service_account'] = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'type' => 'service_account',
            'project_id' => 'proj-test',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'client_email' => 'test@proj-test.iam.gserviceaccount.com',
        ]));
        $row->update(['data' => $data]);
        PanelPushSettings::applyToConfig();

        $user = $this->createSellerUser();

        $response = $this->actingAs($user)->postJson('/painel/push-subscribe', [
            'provider' => 'fcm',
            'fcm_token' => 'fcm-device-token-xyz-12345',
        ]);

        $response->assertOk()->assertJsonPath('provider', 'fcm');
        $this->assertDatabaseHas('panel_push_subscriptions', [
            'user_id' => $user->id,
            'provider' => PanelPushSubscription::PROVIDER_FCM,
            'fcm_token' => 'fcm-device-token-xyz-12345',
        ]);
    }
}
