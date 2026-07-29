<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payout\WithdrawalPayoutDestination;
use App\Support\ApiScopes;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiPayoutDestinationTest extends TestCase
{
    private ?GatewayCredential $cajuCred = null;

    protected function tearDown(): void
    {
        $this->cajuCred?->delete();
        parent::tearDown();
    }

    /**
     * @return array{app: ApiApplication, public: string, secret: string, seller: User}
     */
    private function createWithdrawalApiContext(): array
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
            'name' => 'API Withdrawals',
            'slug' => ApiApplication::generateUniqueSlug((int) $seller->id, 'API Withdrawals'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy-unused'),
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

    private function connectCajuPayPayout(): void
    {
        $this->cajuCred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $this->cajuCred->is_connected = true;
        $this->cajuCred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
            'cajupay_payout_min_brl' => '0',
            'cajupay_admin_fee_pix_brl' => '0',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);
        $this->cajuCred->save();

        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 0, 'fixed' => 0],
            'card' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);
    }

    private function fundWallet(User $seller, float $amount = 500.0): void
    {
        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            [
                'available_balance' => $amount,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => $amount,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    private function apiHeaders(string $public, string $secret): array
    {
        return [
            'X-Public-Key' => $public,
            'X-Secret-Key' => $secret,
            'Accept' => 'application/json',
        ];
    }

    public function test_put_payout_destination_does_not_overwrite_merchant_master_key(): void
    {
        if (! Schema::hasColumn('users', 'payout_settings')) {
            $this->markTestSkipped('payout_settings column');
        }

        $ctx = $this->createWithdrawalApiContext();
        $this->connectCajuPayPayout();

        $masterKey = 'master@infoprodutor.com';
        $ctx['seller']->forceFill([
            'payout_settings' => [
                'cajupay_pix_key' => $masterKey,
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $resp = $this->withHeaders($this->apiHeaders($ctx['public'], $ctx['secret']))
            ->putJson('/api/v1/payout-destination', [
                'pix_key' => 'terceiro@exemplo.com',
                'pix_key_type' => 'email',
                'key_owner_document' => '529.982.247-25',
            ]);

        $resp->assertOk()
            ->assertJsonPath('pix_key_type', 'email')
            ->assertJsonPath('persisted_to_merchant', false)
            ->assertJsonPath('key_owner_document_masked', '*******4725');

        $this->assertSame(
            str_repeat('*', max(0, strlen('terceiro@exemplo.com') - 4)).substr('terceiro@exemplo.com', -4),
            $resp->json('pix_key_masked')
        );

        $ctx['seller']->refresh();
        $settings = is_array($ctx['seller']->payout_settings) ? $ctx['seller']->payout_settings : [];
        $this->assertSame($masterKey, $settings['cajupay_pix_key'] ?? null);
        $this->assertSame('email', $settings['cajupay_pix_key_type'] ?? null);
        $this->assertSame('52998224725', $settings['cajupay_pix_key_owner_document'] ?? null);
        $this->assertNotSame('terceiro@exemplo.com', $settings['cajupay_pix_key'] ?? null);
    }

    public function test_put_payout_destination_email_without_document_returns_422(): void
    {
        if (! Schema::hasColumn('users', 'payout_settings')) {
            $this->markTestSkipped('payout_settings column');
        }

        $ctx = $this->createWithdrawalApiContext();
        $this->connectCajuPayPayout();

        $resp = $this->withHeaders($this->apiHeaders($ctx['public'], $ctx['secret']))
            ->putJson('/api/v1/payout-destination', [
                'pix_key' => 'parceiro@exemplo.com',
                'pix_key_type' => 'email',
            ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['key_owner_document']);
    }

    public function test_put_payout_destination_random_maps_to_evp(): void
    {
        if (! Schema::hasColumn('users', 'payout_settings')) {
            $this->markTestSkipped('payout_settings column');
        }

        $ctx = $this->createWithdrawalApiContext();
        $this->connectCajuPayPayout();

        $evp = '550e8400-e29b-41d4-a716-446655440000';

        $resp = $this->withHeaders($this->apiHeaders($ctx['public'], $ctx['secret']))
            ->putJson('/api/v1/payout-destination', [
                'pix_key' => $evp,
                'pix_key_type' => 'random',
                'key_owner_document' => '52998224725',
            ]);

        $resp->assertOk()
            ->assertJsonPath('pix_key_type', 'evp')
            ->assertJsonPath('persisted_to_merchant', false);

        $ctx['seller']->refresh();
        $this->assertNull($ctx['seller']->payout_settings);
    }

    public function test_put_payout_destination_cpf_auto_derives_owner_document(): void
    {
        if (! Schema::hasColumn('users', 'payout_settings')) {
            $this->markTestSkipped('payout_settings column');
        }

        $ctx = $this->createWithdrawalApiContext();
        $this->connectCajuPayPayout();

        $resp = $this->withHeaders($this->apiHeaders($ctx['public'], $ctx['secret']))
            ->putJson('/api/v1/payout-destination', [
                'pix_key' => '52998224725',
                'pix_key_type' => 'cpf',
            ]);

        $resp->assertOk()
            ->assertJsonPath('pix_key_type', 'cpf')
            ->assertJsonPath('key_owner_document_masked', '*******4725')
            ->assertJsonPath('persisted_to_merchant', false);

        $ctx['seller']->refresh();
        $this->assertNull($ctx['seller']->payout_settings);
    }

    public function test_post_withdrawals_without_destination_returns_422_and_keeps_balance(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        $ctx = $this->createWithdrawalApiContext();
        $this->connectCajuPayPayout();
        $this->fundWallet($ctx['seller'], 500.0);

        $resp = $this->withHeaders($this->apiHeaders($ctx['public'], $ctx['secret']))
            ->postJson('/api/v1/withdrawals', ['amount' => 50.00]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['pix_key']);

        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertEqualsWithDelta(
            500.0,
            (float) TenantWallet::query()->where('tenant_id', $ctx['seller']->id)->value('available_pix'),
            0.01
        );
    }

    public function test_post_withdrawals_stores_destination_on_withdrawal_without_touching_master_key(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        Queue::fake();

        $ctx = $this->createWithdrawalApiContext();
        $this->connectCajuPayPayout();
        $this->fundWallet($ctx['seller'], 500.0);

        $masterKey = 'master@infoprodutor.com';
        $ctx['seller']->forceFill([
            'payout_settings' => [
                'cajupay_pix_key' => $masterKey,
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $thirdPartyKey = 'terceiro@exemplo.com';

        $resp = $this->withHeaders($this->apiHeaders($ctx['public'], $ctx['secret']))
            ->postJson('/api/v1/withdrawals', [
                'amount' => 50.00,
                'pix_key' => $thirdPartyKey,
                'pix_key_type' => 'email',
                'key_owner_document' => '52998224725',
            ]);

        $resp->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount', 50)
            ->assertJsonPath('pix_key_type', 'email');

        $this->assertSame(
            str_repeat('*', max(0, strlen($thirdPartyKey) - 4)).substr($thirdPartyKey, -4),
            $resp->json('pix_key_masked')
        );

        $withdrawal = Withdrawal::query()->where('tenant_id', $ctx['seller']->id)->latest('id')->first();
        $this->assertNotNull($withdrawal);

        $dest = WithdrawalPayoutDestination::fromWithdrawal($withdrawal);
        $this->assertNotNull($dest);
        $this->assertSame($thirdPartyKey, $dest['pix_key']);
        $this->assertSame('email', $dest['pix_key_type']);
        $this->assertSame('52998224725', $dest['key_owner_document']);

        $ctx['seller']->refresh();
        $settings = is_array($ctx['seller']->payout_settings) ? $ctx['seller']->payout_settings : [];
        $this->assertSame($masterKey, $settings['cajupay_pix_key'] ?? null);

        $this->assertEqualsWithDelta(
            450.0,
            (float) TenantWallet::query()->where('tenant_id', $ctx['seller']->id)->value('available_pix'),
            0.01
        );
    }

    public function test_post_withdrawals_with_master_key_only_still_requires_request_destination(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        $ctx = $this->createWithdrawalApiContext();
        $this->connectCajuPayPayout();
        $this->fundWallet($ctx['seller'], 500.0);

        $ctx['seller']->forceFill([
            'payout_settings' => [
                'cajupay_pix_key' => 'master@infoprodutor.com',
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $resp = $this->withHeaders($this->apiHeaders($ctx['public'], $ctx['secret']))
            ->postJson('/api/v1/withdrawals', ['amount' => 50.00]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['pix_key']);

        $this->assertDatabaseCount('withdrawals', 0);
    }
}
