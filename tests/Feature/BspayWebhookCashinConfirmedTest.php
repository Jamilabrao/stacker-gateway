<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BspayWebhookCashinConfirmedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'sync',
            'getfy.api.inbound_webhooks_async' => false,
        ]);
        Event::fake();
    }

    public function test_webhook_completes_order_when_cashin_confirmed_and_api_confirms(): void
    {
        Http::fake([
            'https://api.bspay.co/v2/oauth/token' => Http::response([
                'access_token' => 'jwt-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.bspay.co/v2/account/transactions/list' => Http::response([
                'success' => true,
                'data' => [
                    'items' => [
                        [
                            'transaction_id' => 'tx-bspay-1',
                            'status' => 'confirmed',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]);
        $cred->save();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'bspay',
            'gateway_id' => 'tx-bspay-1',
        ]);

        $response = $this->postSignedBspayWebhook([
            'success' => true,
            'data' => [
                'transaction_id' => 'tx-bspay-1',
                'status' => 'confirmed',
            ],
        ], 'unused');

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_webhook_rejects_invalid_hmac(): void
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'webhook_secret' => 'real-secret',
        ]);
        $cred->save();

        Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'bspay',
            'gateway_id' => 'tx-bspay-1',
        ]);

        $response = $this->postSignedBspayWebhook([
            'data' => ['transaction_id' => 'tx-bspay-1', 'status' => 'confirmed'],
        ], 'wrong-secret');

        $response->assertUnauthorized();
    }
}
