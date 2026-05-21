<?php

namespace Tests\Feature;

use App\Models\PanelPushSubscription;
use App\Models\User;
use App\Support\QueueSyncDispatch;
use Tests\TestCase;

class PlatformBroadcastTest extends TestCase
{
    public function test_push_broadcast_returns_422_without_subscriptions(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('plataforma.app.push.send'), [
                'title' => 'Aviso',
                'body' => 'Teste',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_push_broadcast_returns_422_without_vapid(): void
    {
        config([
            'getfy.pwa.vapid_public' => '',
            'getfy.pwa.vapid_private' => '',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        PanelPushSubscription::create([
            'user_id' => User::factory()->create(['tenant_id' => 1])->id,
            'tenant_id' => 1,
            'endpoint' => 'https://push.example/sub/1',
            'keys' => ['auth' => 'authkey', 'p256dh' => 'p256dhkey'],
        ]);

        $this->actingAs($admin)
            ->postJson(route('plataforma.app.push.send'), [
                'title' => 'Aviso',
                'body' => 'Teste',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_queue_sync_dispatch_defaults_to_sync_in_tests(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertTrue(QueueSyncDispatch::shouldRunSynchronously());
    }
}
