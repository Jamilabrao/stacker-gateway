<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KycRegistrationTest extends TestCase
{
    public function test_cadastro_redirects_when_no_users_exist(): void
    {
        $this->assertSame(0, User::count());

        $this->get('/cadastro')->assertRedirect(route('criar-admin'));
    }

    public function test_cadastro_creates_infoprodutor_with_kyc_pending(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $payload = [
            'person_type' => 'pf',
            'name' => 'Vendedor Teste',
            'email' => 'vendedor@example.com',
            'birth_date' => '1990-05-15',
            'document' => '52998224725',
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
        ];

        $response = $this->post('/cadastro', $payload);

        $response->assertRedirect('/financeiro?tab=seus-dados');

        $u = User::query()->where('email', 'vendedor@example.com')->first();
        $this->assertNotNull($u);
        $this->assertSame(User::ROLE_INFOPRODUTOR, $u->role);
        $this->assertSame(User::KYC_NOT_SUBMITTED, $u->kyc_status);
        $this->assertSame('pending', $u->account_status);
        $this->assertAuthenticatedAs($u);
    }

    public function test_dashboard_redirects_when_kyc_not_submitted(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller',
            'email' => 'seller-gate@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertRedirect('/financeiro?tab=seus-dados');
    }

    public function test_validar_documento_disponivel_para_cpf_livre(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $payload = [
            'person_type' => 'pf',
            'document' => '52998224725',
            'legal_representative_cpf' => null,
        ];

        $this->postJson('/cadastro/validar-documento', $payload)
            ->assertOk()
            ->assertJson(['available' => true]);
    }

    public function test_validar_documento_bloqueia_cpf_ja_cadastrado(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        User::query()->create([
            'name' => 'Outro',
            'email' => 'outro@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => null,
            'document' => '52998224725',
            'person_type' => 'pf',
        ]);

        $this->postJson('/cadastro/validar-documento', [
            'person_type' => 'pf',
            'document' => '52998224725',
            'legal_representative_cpf' => null,
        ])
            ->assertOk()
            ->assertJson([
                'available' => false,
                'field' => 'document',
            ]);
    }

    public function test_pending_review_cannot_access_dashboard(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller Review',
            'email' => 'seller-review@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertRedirect('/financeiro?tab=seus-dados');
    }

    public function test_finance_pix_blocked_without_kyc(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller',
            'email' => 'seller@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $this->actingAs($seller);

        $response = $this->post('/financeiro/pix-saque', [
            'label' => 'Principal',
            'pix_key_type' => 'cpf',
            'pix_key' => '52998224725',
        ]);

        $response->assertRedirect('/financeiro?tab=seus-dados');
    }
}
