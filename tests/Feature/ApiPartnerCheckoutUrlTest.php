<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\ApiScopes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiPartnerCheckoutUrlTest extends TestCase
{
    /**
     * @return array{app: ApiApplication, public: string, secret: string, seller: User}
     */
    private function createApiContext(): array
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();

        $app = ApiApplication::create([
            'tenant_id' => $seller->id,
            'name' => 'API Partner',
            'slug' => ApiApplication::generateUniqueSlug((int) $seller->id, 'API Partner'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy'),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
            'webhook_url' => null,
            'default_return_url' => null,
            'webhook_secret' => null,
            'checkout_sidebar_bg' => null,
        ]);

        return ['app' => $app, 'public' => $public, 'secret' => $secret, 'seller' => $seller];
    }

    private function enableCajuPayPix(): GatewayCredential
    {
        Setting::set('api_pix_enabled', '1', null);
        Setting::set('gateway_order', [
            'pix' => ['cajupay'],
            'card' => [],
            'boleto' => [],
            'pix_auto' => [],
        ], null);

        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
        ]);
        $cred->save();

        return $cred;
    }

    public function test_create_pix_with_partner_checkout_url_persists_and_forwards_to_cajupay(): void
    {
        if (! Schema::hasColumn('orders', 'api_application_id')) {
            $this->markTestSkipped('api_application_id column');
        }

        $ctx = $this->createApiContext();
        $cred = $this->enableCajuPayPix();

        $product = $this->createTestProduct([
            'tenant_id' => $ctx['seller']->id,
            'price' => 50.00,
        ]);

        $partnerUrl = 'https://loja.exemplo.com/checkout/ped-1001';

        Http::fake([
            'https://api.cajupay.com.br/api/payments/pix' => function ($request) use ($partnerUrl) {
                $body = $request->data();
                $this->assertSame($partnerUrl, $body['partner_checkout_url'] ?? null);

                return Http::response([
                    'payment_id' => 'pay_partner_1',
                    'pix_copy_paste' => '00020126580014BR.GOV.BCB.PIX',
                    'pix_qr_code' => 'data:image/png;base64,abc',
                ], 201);
            },
        ]);

        $response = $this->withHeaders([
            'X-Public-Key' => $ctx['public'],
            'X-Secret-Key' => $ctx['secret'],
        ])->postJson('/api/v1/payments/pix', [
            'customer' => [
                'email' => 'cliente@exemplo.com',
                'name' => 'Cliente',
                'cpf' => '52998224725',
            ],
            'amount' => 50.00,
            'product_id' => (string) $product->id,
            'partner_checkout_url' => $partnerUrl,
        ]);

        $response->assertCreated();

        $order = Order::find($response->json('order_id'));
        $this->assertNotNull($order);
        $this->assertSame($partnerUrl, $order->metadata['partner_checkout_url'] ?? null);
        $this->assertSame('api', $order->metadata['source'] ?? null);

        $cred->delete();
    }

    public function test_create_pix_without_partner_checkout_url_omits_field_on_cajupay(): void
    {
        if (! Schema::hasColumn('orders', 'api_application_id')) {
            $this->markTestSkipped('api_application_id column');
        }

        $ctx = $this->createApiContext();
        $cred = $this->enableCajuPayPix();

        $product = $this->createTestProduct([
            'tenant_id' => $ctx['seller']->id,
            'price' => 25.00,
        ]);

        Http::fake([
            'https://api.cajupay.com.br/api/payments/pix' => function ($request) {
                $body = $request->data();
                $this->assertArrayNotHasKey('partner_checkout_url', $body);

                return Http::response([
                    'payment_id' => 'pay_no_partner',
                    'pix_copy_paste' => '00020126580014BR.GOV.BCB.PIX',
                    'pix_qr_code' => 'data:image/png;base64,abc',
                ], 201);
            },
        ]);

        $response = $this->withHeaders([
            'X-Public-Key' => $ctx['public'],
            'X-Secret-Key' => $ctx['secret'],
        ])->postJson('/api/v1/payments/pix', [
            'customer' => [
                'email' => 'cliente@exemplo.com',
                'cpf' => '52998224725',
            ],
            'amount' => 25.00,
            'product_id' => (string) $product->id,
        ]);

        $response->assertCreated();
        $cred->delete();
    }

    public function test_create_pix_rejects_non_https_partner_checkout_url(): void
    {
        $ctx = $this->createApiContext();
        $this->enableCajuPayPix();

        $product = $this->createTestProduct([
            'tenant_id' => $ctx['seller']->id,
            'price' => 10.00,
        ]);

        $response = $this->withHeaders([
            'X-Public-Key' => $ctx['public'],
            'X-Secret-Key' => $ctx['secret'],
        ])->postJson('/api/v1/payments/pix', [
            'customer' => ['email' => 'cliente@exemplo.com', 'cpf' => '52998224725'],
            'amount' => 10.00,
            'product_id' => (string) $product->id,
            'partner_checkout_url' => 'http://inseguro.exemplo.com/checkout',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['partner_checkout_url']);
    }

    public function test_platform_api_transactions_lists_only_direct_api_pix(): void
    {
        if (! Schema::hasTable('orders')) {
            $this->markTestSkipped('orders table');
        }

        $ctx = $this->createApiContext();
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);

        $product = $this->createTestProduct(['tenant_id' => $ctx['seller']->id, 'price' => 50]);

        $apiPix = Order::create([
            'tenant_id' => $ctx['seller']->id,
            'user_id' => $ctx['seller']->id,
            'product_id' => $product->id,
            'api_application_id' => $ctx['app']->id,
            'status' => 'pending',
            'amount' => 50,
            'email' => 'api@exemplo.com',
            'payment_method' => 'pix',
            'metadata' => ['source' => 'api', 'partner_checkout_url' => 'https://loja.com/p1'],
        ]);

        $checkoutPro = Order::create([
            'tenant_id' => $ctx['seller']->id,
            'user_id' => $ctx['seller']->id,
            'product_id' => $product->id,
            'api_application_id' => $ctx['app']->id,
            'status' => 'pending',
            'amount' => 60,
            'email' => 'pro@exemplo.com',
            'payment_method' => 'pix',
            'metadata' => ['source' => 'api_checkout_pro'],
        ]);

        $internal = Order::create([
            'tenant_id' => $ctx['seller']->id,
            'user_id' => $ctx['seller']->id,
            'product_id' => $product->id,
            'api_application_id' => null,
            'status' => 'completed',
            'amount' => 70,
            'email' => 'interno@exemplo.com',
            'payment_method' => 'pix',
            'metadata' => ['source' => 'checkout'],
        ]);

        $response = $this->actingAs($admin)->get('/plataforma/transacoes-api');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/ApiTransactions/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $apiPix->id)
                ->where('orders.data.0.partner_checkout_url', 'https://loja.com/p1'));
    }
}
