<?php

namespace Tests\Feature;

use App\Models\PlatformAuditLog;
use App\Models\ProductCoproducer;
use App\Models\Setting;
use App\Models\User;
use App\Support\InfoproducerRegistrationSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InfoproducerRegistrationGateTest extends TestCase
{
    private function seedPlatformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seedActiveSeller(string $email = 'seller-host@example.com'): User
    {
        $seller = User::factory()->create([
            'name' => 'Seller Ativo',
            'email' => $email,
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        $uniq = str_replace('.', '', uniqid('reg', true));

        return array_merge([
            'person_type' => 'pf',
            'name' => 'Novo Infoprodutor',
            'email' => "novo.{$uniq}@example.com",
            'phone' => '11999887766',
            'birth_date' => '1990-05-15',
            'document' => '11144477735',
            'company_name' => null,
            'legal_representative_cpf' => null,
            'address_zip' => '01310100',
            'address_street' => 'Av Paulista',
            'address_number' => '1000',
            'address_complement' => '',
            'address_neighborhood' => 'Bela Vista',
            'address_city' => 'São Paulo',
            'address_state' => 'SP',
            'monthly_revenue_range' => 'up_to_10k',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_terms_privacy' => '1',
        ], $overrides);
    }

    public function test_default_allows_registration(): void
    {
        $this->seedActiveSeller();

        $this->assertTrue(InfoproducerRegistrationSettings::isAllowed());

        $this->get(route('cadastro'))->assertOk();

        $payload = $this->registrationPayload(['document' => '52998224725']);
        $this->post('/cadastro', $payload)->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
    }

    public function test_closed_blocks_page_and_store_without_invite(): void
    {
        $this->seedActiveSeller();
        Setting::set(InfoproducerRegistrationSettings::KEY, '0', null);

        $this->get(route('cadastro'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', InfoproducerRegistrationSettings::BLOCKED_MESSAGE);

        $payload = $this->registrationPayload(['document' => '15350946056']);
        $this->post('/cadastro', $payload)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', InfoproducerRegistrationSettings::BLOCKED_MESSAGE);

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_closed_blocks_json_validation_endpoints(): void
    {
        $this->seedActiveSeller();
        Setting::set(InfoproducerRegistrationSettings::KEY, '0', null);

        $this->postJson('/cadastro/validar-email', ['email' => 'x@example.com'])
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => InfoproducerRegistrationSettings::BLOCKED_MESSAGE,
            ]);
    }

    public function test_closed_allows_registration_with_valid_coproduction_invite(): void
    {
        if (! Schema::hasTable('product_coproducers')) {
            $this->markTestSkipped('product_coproducers table');
        }

        $seller = $this->seedActiveSeller();
        Setting::set(InfoproducerRegistrationSettings::KEY, '0', null);

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $token = Str::random(48);
        $email = 'convidado.coprod@example.com';

        ProductCoproducer::query()->create([
            'product_id' => $product->id,
            'inviter_user_id' => $seller->id,
            'email' => $email,
            'status' => ProductCoproducer::STATUS_PENDING,
            'token' => $token,
            'commission_percent' => 10,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ]);

        $this->get('/cadastro?coproducer_invite='.$token)->assertOk();

        $payload = $this->registrationPayload([
            'email' => $email,
            'document' => '39053344705',
            'coproducer_invite' => $token,
        ]);

        $this->post('/cadastro', $payload)->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
    }

    public function test_closed_rejects_fake_invite_token(): void
    {
        $this->seedActiveSeller();
        Setting::set(InfoproducerRegistrationSettings::KEY, '0', null);

        $payload = $this->registrationPayload([
            'document' => '11144477735',
            'coproducer_invite' => 'token-falso-invalido',
        ]);

        $this->post('/cadastro', $payload)
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_closed_allows_registration_with_active_seller_referral(): void
    {
        if (! Schema::hasColumn('users', 'referral_code')) {
            $this->markTestSkipped('referral_code column');
        }

        $seller = $this->seedActiveSeller('referrer@example.com');
        $seller->forceFill(['referral_code' => 'ABCD1234', 'account_status' => 'approved'])->save();
        Setting::set(InfoproducerRegistrationSettings::KEY, '0', null);
        Setting::set('referral_program_enabled', '1', null);

        $this->get('/cadastro?ref=ABCD1234')->assertOk();

        $payload = $this->registrationPayload([
            'document' => '52998224725',
            'ref' => 'ABCD1234',
        ]);

        $this->post('/cadastro', $payload)->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
    }

    public function test_admin_can_create_infoprodutor_when_closed(): void
    {
        $admin = $this->seedPlatformAdmin();
        Setting::set(InfoproducerRegistrationSettings::KEY, '0', null);

        $this->actingAs($admin)
            ->post(route('plataforma.usuarios.store'), [
                'name' => 'Admin Created',
                'email' => 'admin.created@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'admin.created@example.com',
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
    }

    public function test_existing_seller_login_works_when_closed(): void
    {
        $seller = User::factory()->create([
            'email' => 'existing.seller@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        Setting::set(InfoproducerRegistrationSettings::KEY, '0', null);

        $this->post('/login', [
            'email' => 'existing.seller@example.com',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($seller);
    }

    public function test_platform_admin_can_toggle_setting_and_audit(): void
    {
        $admin = $this->seedPlatformAdmin();
        $this->assertTrue(InfoproducerRegistrationSettings::isAllowed());

        $this->actingAs($admin)
            ->put(route('plataforma.settings.update'), [
                'allow_new_infoproducers' => false,
                'checkout_translations' => config('checkout_translations'),
                'currencies' => config('products.currencies'),
                'email_provider' => 'smtp',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '587',
                'smtp_username' => 'user@example.com',
                'smtp_encryption' => 'tls',
                'mail_from_address' => 'noreply@example.com',
                'mail_from_name' => 'Getfy',
            ])
            ->assertRedirect();

        $this->assertFalse(InfoproducerRegistrationSettings::isAllowed());
        $this->assertSame('0', Setting::get(InfoproducerRegistrationSettings::KEY, null, null));

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertTrue(
                PlatformAuditLog::query()
                    ->where('action', 'settings.infoproducer_registration.updated')
                    ->exists()
            );
        }

        $seller = $this->seedActiveSeller('no-perm@example.com');
        $this->actingAs($seller)
            ->put(route('plataforma.settings.update'), [
                'allow_new_infoproducers' => true,
            ])
            ->assertForbidden();
    }

    public function test_login_inertia_hides_flag_when_closed(): void
    {
        $this->seedActiveSeller();
        Setting::set(InfoproducerRegistrationSettings::KEY, '0', null);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('allow_new_infoproducers', false));
    }
}
