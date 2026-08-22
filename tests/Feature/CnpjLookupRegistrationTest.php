<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Cnpj\BrasilApiCnpjClient;
use App\Support\CnpjLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CnpjLookupRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function seedAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-cnpj@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pjPayload(array $overrides = []): array
    {
        return array_merge([
            'person_type' => 'pj',
            'name' => 'Representante Teste',
            'email' => 'pj-'.uniqid().'@example.com',
            'phone' => '11999887766',
            'birth_date' => '1990-05-15',
            'document' => '11222333000181',
            'company_name' => 'Empresa Teste LTDA',
            'legal_representative_cpf' => '52998224725',
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

    /**
     * @return array<string, mixed>
     */
    private function brasilApiPayload(array $overrides = []): array
    {
        return array_merge([
            'cnpj' => '11222333000181',
            'razao_social' => 'EMPRESA TESTE LTDA',
            'nome_fantasia' => 'Empresa Teste',
            'descricao_situacao_cadastral' => 'ATIVA',
            'data_inicio_atividade' => '2018-01-10',
            'cnae_fiscal_descricao' => 'Desenvolvimento de programas',
            'natureza_juridica' => 'Sociedade Empresária Limitada',
            'descricao_tipo_de_logradouro' => 'AVENIDA',
            'logradouro' => 'PAULISTA',
            'numero' => '1000',
            'bairro' => 'BELA VISTA',
            'cep' => '01310100',
            'municipio' => 'SAO PAULO',
            'uf' => 'SP',
            'qsa' => [
                ['nome_socio' => 'REPRESENTANTE TESTE', 'qualificacao_socio' => 'Sócio-Administrador'],
            ],
        ], $overrides);
    }

    public function test_lookup_endpoint_suggests_razao_social(): void
    {
        $this->seedAdmin();
        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response($this->brasilApiPayload(), 200),
        ]);

        $this->postJson('/cadastro/consultar-cnpj', ['document' => '11222333000181'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'ok',
                'razao_social' => 'EMPRESA TESTE LTDA',
                'situacao' => 'ATIVA',
                'situacao_irregular' => false,
            ]);
    }

    public function test_lookup_endpoint_warns_when_cnpj_is_baixado(): void
    {
        $this->seedAdmin();
        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response($this->brasilApiPayload([
                'descricao_situacao_cadastral' => 'BAIXADA',
            ]), 200),
        ]);

        $this->postJson('/cadastro/consultar-cnpj', ['document' => '11222333000181'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'situacao' => 'BAIXADA',
                'situacao_irregular' => true,
            ])
            ->assertJsonFragment(['situacao_message' => CnpjLookup::sellerSituacaoMessage('BAIXADA')]);
    }

    public function test_lookup_endpoint_does_not_fail_when_api_is_down(): void
    {
        $this->seedAdmin();
        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response(['message' => 'error'], 503),
        ]);

        $this->postJson('/cadastro/consultar-cnpj', ['document' => '11222333000181'])
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'status' => BrasilApiCnpjClient::STATUS_UNAVAILABLE,
            ]);
    }

    public function test_pj_registration_continues_when_api_fails_and_flags_kyc(): void
    {
        $this->seedAdmin();
        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response(['message' => 'error'], 503),
        ]);

        $payload = $this->pjPayload();
        $this->post('/cadastro', $payload)->assertRedirect();

        $user = User::query()->where('email', $payload['email'])->first();
        $this->assertNotNull($user);
        $this->assertSame('Empresa Teste LTDA', $user->company_name);
        $this->assertSame(BrasilApiCnpjClient::STATUS_UNAVAILABLE, $user->cnpj_lookup['status'] ?? null);
        $this->assertNotEmpty($user->cnpj_lookup['last_error'] ?? null);

        $view = CnpjLookup::forKycAdmin($user);
        $this->assertTrue($view['needs_attention']);
        $this->assertContains('lookup_failed', array_column($view['alerts'], 'code'));
    }

    public function test_pj_registration_stores_official_data_and_detects_edited_razao(): void
    {
        $this->seedAdmin();
        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response($this->brasilApiPayload(), 200),
        ]);

        $payload = $this->pjPayload([
            'company_name' => 'Marca Que Eu Inventei',
            'cnpj_suggested_razao_social' => 'EMPRESA TESTE LTDA',
        ]);
        $this->post('/cadastro', $payload)->assertRedirect();

        $user = User::query()->where('email', $payload['email'])->first();
        $this->assertNotNull($user);
        $this->assertSame('Marca Que Eu Inventei', $user->company_name);
        $this->assertSame('EMPRESA TESTE LTDA', $user->cnpj_lookup['razao_social'] ?? null);
        $this->assertTrue($user->cnpj_lookup['razao_social_overridden'] ?? false);
        $this->assertFalse($user->cnpj_lookup['situacao_irregular'] ?? true);

        $view = CnpjLookup::forKycAdmin($user);
        $this->assertTrue($view['needs_attention']);
        $this->assertContains('razao_editada', array_column($view['alerts'], 'code'));
    }

    public function test_pj_registration_does_not_flag_override_when_razao_matches(): void
    {
        $this->seedAdmin();
        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response($this->brasilApiPayload(), 200),
        ]);

        $payload = $this->pjPayload([
            'company_name' => 'Empresa Teste Ltda.',
        ]);
        $this->post('/cadastro', $payload)->assertRedirect();

        $user = User::query()->where('email', $payload['email'])->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->cnpj_lookup['razao_social_overridden'] ?? true);

        $view = CnpjLookup::forKycAdmin($user);
        $this->assertFalse($view['needs_attention']);
    }

    public function test_admin_can_refresh_cnpj_and_compare(): void
    {
        $admin = $this->seedAdmin();
        $seller = User::query()->create([
            'name' => 'PJ Seller',
            'email' => 'pj-kyc@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'person_type' => 'pj',
            'document' => '11222333000181',
            'company_name' => 'Nome Digitado',
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
            'cnpj_lookup' => [
                'status' => BrasilApiCnpjClient::STATUS_UNAVAILABLE,
                'has_official_data' => false,
                'last_error' => 'http_503',
            ],
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response($this->brasilApiPayload(), 200),
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.refresh-cnpj', $seller))
            ->assertRedirect(route('plataforma.kyc.show', $seller))
            ->assertSessionHas('success');

        $seller->refresh();
        $this->assertSame('EMPRESA TESTE LTDA', $seller->cnpj_lookup['razao_social'] ?? null);
        $this->assertTrue($seller->cnpj_lookup['razao_social_overridden'] ?? false);

        $this->actingAs($admin)
            ->get(route('plataforma.kyc.show', $seller))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Kyc/Show')
                ->where('cnpj_lookup.needs_attention', true)
                ->where('cnpj_lookup.official.razao_social', 'EMPRESA TESTE LTDA')
                ->where('cnpj_lookup.submitted.company_name', 'Nome Digitado')
            );
    }

    public function test_admin_refresh_keeps_previous_snapshot_when_api_fails(): void
    {
        $admin = $this->seedAdmin();
        $seller = User::query()->create([
            'name' => 'PJ Seller 2',
            'email' => 'pj-kyc-2@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'person_type' => 'pj',
            'document' => '11222333000181',
            'company_name' => 'EMPRESA TESTE LTDA',
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
            'cnpj_lookup' => CnpjLookup::fromOfficialPayload($this->brasilApiPayload(), 'EMPRESA TESTE LTDA', '11222333000181'),
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        Http::fake([
            'brasilapi.com.br/api/cnpj/v1/*' => Http::response(['message' => 'error'], 503),
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.refresh-cnpj', $seller))
            ->assertRedirect(route('plataforma.kyc.show', $seller))
            ->assertSessionHas('error');

        $seller->refresh();
        $this->assertSame('EMPRESA TESTE LTDA', $seller->cnpj_lookup['razao_social'] ?? null);
        $this->assertNotEmpty($seller->cnpj_lookup['last_error'] ?? null);
    }

    public function test_pf_registration_does_not_call_brasilapi(): void
    {
        $this->seedAdmin();
        Http::fake([
            'brasilapi.com.br/*' => Http::response('should-not-hit', 500),
        ]);

        $this->post('/cadastro', [
            'person_type' => 'pf',
            'name' => 'Vendedor PF',
            'email' => 'pf-'.uniqid().'@example.com',
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
        ])->assertRedirect();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'brasilapi.com.br'));
    }
}
