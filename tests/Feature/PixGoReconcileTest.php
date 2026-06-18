<?php

namespace Tests\Feature;

use App\Gateways\Contracts\GatewayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PixGoReconcileTest extends TestCase
{
    public function test_reconcile_pixgo_skips_orders_past_reconcile_window(): void
    {
        Event::fake();

        GatewayRegistry::register([
            'slug' => 'fake-pixgo',
            'name' => 'Fake PixGO',
            'image' => '',
            'methods' => ['pix'],
            'scope' => 'national',
            'signup_url' => '',
            'driver' => PixGoFakeGatewayDriver::class,
            'credential_keys' => [],
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $expired = Order::create([
            'tenant_id' => $seller->id,
            'status' => 'pending',
            'amount' => 15.00,
            'email' => 'a@example.com',
            'gateway' => 'fake-pixgo',
            'gateway_id' => 'tx-expired',
            'metadata' => [
                'source' => 'pixgo',
                'reconcile_until' => now()->subHour()->toIso8601String(),
            ],
        ]);

        $active = Order::create([
            'tenant_id' => $seller->id,
            'status' => 'pending',
            'amount' => 20.00,
            'email' => 'b@example.com',
            'gateway' => 'fake-pixgo',
            'gateway_id' => 'tx-active',
            'metadata' => [
                'source' => 'pixgo',
                'reconcile_until' => now()->addHours(12)->toIso8601String(),
            ],
        ]);

        $credential = GatewayCredential::create([
            'tenant_id' => $seller->id,
            'gateway_slug' => 'fake-pixgo',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['k' => 'v']);
        $credential->save();

        Artisan::call('payments:reconcile-pending', [
            '--source' => 'pixgo',
            '--limit' => 10,
            '--days' => 1,
            '--min-age-minutes' => 0,
        ]);

        $expired->refresh();
        $active->refresh();

        $this->assertSame('pending', $expired->status);
        $this->assertSame('completed', $active->status);
    }
}

class PixGoFakeGatewayDriver implements GatewayDriver
{
    public function testConnection(array $credentials): bool
    {
        return true;
    }

    public function createPixPayment(array $credentials, float $amount, array $consumer, string $externalId, string $postbackUrl): array
    {
        return ['transaction_id' => 'tx_1'];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        return $transactionId === 'tx-active' ? 'paid' : 'pending';
    }

    public function createCardPayment(array $credentials, float $amount, array $consumer, string $externalId, array $card): array
    {
        return ['transaction_id' => 'tx_1', 'status' => 'paid'];
    }

    public function createBoletoPayment(array $credentials, float $amount, array $consumer, string $externalId, string $notificationUrl): array
    {
        return [
            'transaction_id' => 'tx_1',
            'amount' => $amount,
            'expire_at' => now()->addDays(3)->toDateString(),
            'barcode' => '123',
            'pdf_url' => 'https://example.com/boleto.pdf',
        ];
    }
}
