<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\KycDocument;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Support\KycRequiredDocuments;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MerchantKycSecurityTest extends TestCase
{
    private function seedPlatformAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-kyc@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function createSeller(array $overrides = []): User
    {
        $seller = User::query()->create(array_merge([
            'name' => 'Seller',
            'email' => 'seller-kyc-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ], $overrides));
        $seller->update(['tenant_id' => $seller->id]);

        return $seller->fresh();
    }

    public function test_pending_review_cannot_access_dashboard_or_products(): void
    {
        $seller = $this->createSeller();

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertRedirect('/financeiro?tab=seus-dados');

        $this->actingAs($seller)
            ->get('/produtos')
            ->assertRedirect('/financeiro?tab=seus-dados');
    }

    public function test_platform_admin_create_merchant_starts_pending_without_kyc(): void
    {
        $admin = $this->seedPlatformAdmin();

        $this->actingAs($admin)->post(route('plataforma.usuarios.store'), [
            'name' => 'Novo Vendedor',
            'email' => 'novo-vendedor@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertRedirect(route('plataforma.usuarios.index'));

        $user = User::query()->where('email', 'novo-vendedor@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('pending', $user->account_status);
        $this->assertSame(User::KYC_NOT_SUBMITTED, $user->kyc_status);
    }

    public function test_kyc_approve_requires_documents(): void
    {
        $admin = $this->seedPlatformAdmin();
        $seller = $this->createSeller([
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.approve', $seller))
            ->assertSessionHasErrors('kyc');

        foreach (KycRequiredDocuments::kindsForUser($seller) as $kind) {
            KycDocument::query()->create([
                'user_id' => $seller->id,
                'kind' => $kind,
                'disk_path' => 'kyc/test/'.$kind.'.jpg',
                'original_mime' => 'image/jpeg',
                'size_bytes' => 1000,
                'public_token' => 'tok-'.$kind,
            ]);
        }

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.approve', $seller))
            ->assertRedirect(route('plataforma.kyc.show', $seller));

        $seller->refresh();
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
        $this->assertSame('approved', $seller->account_status);
    }

    /**
     * @return array{app: ApiApplication, public: string, secret: string}
     */
    private function createApiApp(int $tenantId): array
    {
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();

        $app = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'API App',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'API App'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy_test_key'),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'webhook_url' => null,
            'default_return_url' => null,
            'webhook_secret' => null,
            'checkout_sidebar_bg' => null,
        ]);

        return ['app' => $app, 'public' => $public, 'secret' => $secret];
    }

    public function test_api_payments_rejected_when_merchant_not_operationally_approved(): void
    {
        Setting::set('api_pix_enabled', true, null);

        $seller = $this->createSeller([
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ]);

        $keys = $this->createApiApp((int) $seller->tenant_id);

        $response = $this->withHeaders([
            'X-Public-Key' => $keys['public'],
            'X-Secret-Key' => $keys['secret'],
        ])->postJson('/api/v1/payments/pix', [
            'amount' => 10.00,
            'customer' => ['email' => 'buyer@example.com', 'name' => 'Buyer'],
        ]);

        $response->assertForbidden();
    }

    public function test_withdrawal_blocked_for_pending_review_merchant(): void
    {
        $seller = $this->createSeller([
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ]);

        TenantWallet::query()->create([
            'tenant_id' => $seller->tenant_id,
            'available_pix' => 100,
            'available_card' => 0,
            'available_boleto' => 0,
            'pending_balance' => 0,
            'available_balance' => 100,
            'currency' => 'BRL',
        ]);

        $this->actingAs($seller)
            ->post('/financeiro/saque', [
                'amount' => 10,
                'bucket' => 'pix',
            ])
            ->assertRedirect('/financeiro?tab=seus-dados');
    }

    public function test_flag_missing_documents_command_marks_review_without_downgrading_kyc(): void
    {
        $seller = User::query()->create([
            'name' => 'Legacy',
            'email' => 'legacy@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ]);
        $seller->update(['tenant_id' => $seller->id]);
        $seller->forceFill(['kyc_needs_document_review' => false])->save();

        $this->artisan('kyc:flag-missing-documents')
            ->assertSuccessful();

        $seller->refresh();
        $this->assertTrue((bool) $seller->kyc_needs_document_review);
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
        $this->assertTrue($seller->isMerchantOperationallyApproved());
    }
}
