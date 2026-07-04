<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformIntegraxSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegraxTestSmsTest extends TestCase
{
    public function test_test_endpoint_sends_sms(): void
    {
        Http::fake([
            'sms.aresfun.com/*' => Http::response(['ok' => true], 200),
        ]);

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'api_token' => 'test-api-token',
            'sender_from' => '29094',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('plataforma.integrax.test'), [
                'phone' => '11999887766',
                'message' => 'Teste SMS IntegraX',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'test-api-token/send-sms')
                && ($body['to'][0] ?? '') === '5511999887766'
                && ($body['from'] ?? '') === '29094'
                && ($body['message'] ?? '') === 'Teste SMS IntegraX';
        });
    }
}
