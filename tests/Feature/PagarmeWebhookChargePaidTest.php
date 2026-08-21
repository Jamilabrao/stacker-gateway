<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PagarmeWebhookChargePaidTest extends TestCase
{
    private function seedPagarmeCredentials(string $secret = 'sk_test_pagarme'): GatewayCredential
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'pagarme',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'secret_key' => $secret,
            'public_key' => 'pk_test_pagarme',
        ]);
        $cred->save();

        return $cred;
    }

    private function createPendingPagarmeOrder(string $chargeId): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'Pagarme PIX']);

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 6400,
            'email' => 'buyer@example.com',
            'payment_method' => 'pix',
            'gateway' => 'pagarme',
            'gateway_id' => $chargeId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePaidPayload(string $chargeId): array
    {
        return [
            'id' => 'hook_test_v5_paid',
            'account' => [
                'id' => 'acc_test',
                'name' => 'Test Account',
            ],
            'type' => 'charge.paid',
            'created_at' => '2026-08-21T19:58:49.5077656Z',
            'data' => [
                'id' => $chargeId,
                'code' => 'ord_test_1451',
                'status' => 'paid',
                'payment_method' => 'pix',
                'amount' => 640000,
                'paid_amount' => 640000,
                'last_transaction' => [
                    'id' => 'tran_test_paid',
                    'status' => 'paid',
                    'transaction_type' => 'pix',
                    'success' => true,
                ],
                'order' => [
                    'id' => 'or_test_paid',
                    'code' => 'ord_test_1451',
                    'status' => 'paid',
                ],
            ],
        ];
    }

    public function test_v5_charge_paid_without_hub_signature_completes_pending_order(): void
    {
        $chargeId = 'ch_test_v5_paid';
        $this->seedPagarmeCredentials();
        $order = $this->createPendingPagarmeOrder($chargeId);

        Http::fake([
            'https://api.pagar.me/core/v5/charges/'.$chargeId => Http::response([
                'id' => $chargeId,
                'status' => 'paid',
                'last_transaction' => [
                    'status' => 'paid',
                    'transaction_type' => 'pix',
                ],
            ], 200),
        ]);

        $this->postJson('/webhooks/gateways/pagarme', $this->chargePaidPayload($chargeId))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_invalid_hub_signature_is_rejected(): void
    {
        $chargeId = 'ch_test_invalid_sig';
        $this->seedPagarmeCredentials('sk_test_pagarme');
        $this->createPendingPagarmeOrder($chargeId);

        $payload = $this->chargePaidPayload($chargeId);
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/gateways/pagarme', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE' => 'sha1=deadbeef',
        ], $raw)
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthorized']);
    }
}
