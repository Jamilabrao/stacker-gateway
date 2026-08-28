<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\KycDocument;
use App\Models\User;
use App\Support\KycRequiredDocuments;
use App\Support\PjConversion;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PjConversionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        Http::fake([
            'brasilapi.com.br/*' => Http::response([
                'cnpj' => '11222333000181',
                'razao_social' => 'EMPRESA CONVERTIDA LTDA',
                'nome_fantasia' => 'Convertida',
                'descricao_situacao_cadastral' => 'ATIVA',
                'natureza_juridica' => '206-2 Sociedade Empresária Limitada',
            ], 200),
        ]);
    }

    private function createApprovedPfSeller(array $overrides = []): User
    {
        $seller = User::query()->create(array_merge([
            'name' => 'Marina Costa',
            'email' => 'pj-conv-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'person_type' => 'pf',
            'document' => '39053344705',
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
            'identity_document_type' => 'rg',
            'kyc_requirements_version' => 1,
        ], $overrides));
        $seller->update(['tenant_id' => $seller->id]);

        foreach ([KycDocument::KIND_RG_FRONT, KycDocument::KIND_RG_BACK] as $kind) {
            KycDocument::query()->create([
                'user_id' => $seller->id,
                'kind' => $kind,
                'disk_path' => 'kyc/test/'.$kind.'.jpg',
                'original_mime' => 'image/jpeg',
                'size_bytes' => 1000,
                'public_token' => 'tok-'.$seller->id.'-'.$kind,
            ]);
        }

        return $seller->fresh();
    }

    private function seedPlatformAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin Conv',
            'email' => 'admin-pj-conv-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function img(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 300);
    }

    public function test_approved_pf_seller_stays_operational_while_conversion_is_pending(): void
    {
        $seller = $this->createApprovedPfSeller();

        $this->actingAs($seller)
            ->post(route('profile.pj-conversion.start'), [
                'cnpj' => '11.222.333/0001-81',
                'company_name' => 'Empresa Convertida LTDA',
                'company_legal_nature' => 'other',
            ])
            ->assertRedirect(route('profile.index'));

        $seller->refresh();
        $this->assertSame('pf', $seller->person_type);
        $this->assertSame('39053344705', $seller->document);
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
        $this->assertTrue($seller->isMerchantOperationallyApproved());
        $this->assertSame(PjConversion::STATUS_COLLECTING, PjConversion::status($seller));
        $this->assertSame('11222333000181', PjConversion::cnpj($seller));
    }

    public function test_duplicate_cnpj_is_rejected(): void
    {
        $other = $this->createApprovedPfSeller([
            'email' => 'other-'.uniqid().'@example.com',
            'person_type' => 'pj',
            'document' => '11222333000181',
            'legal_representative_cpf' => '52998224725',
        ]);
        $this->assertSame('pj', $other->person_type);

        $seller = $this->createApprovedPfSeller();

        $this->actingAs($seller)
            ->post(route('profile.pj-conversion.start'), [
                'cnpj' => '11222333000181',
                'company_name' => 'Empresa Convertida LTDA',
                'company_legal_nature' => 'other',
            ])
            ->assertSessionHasErrors('cnpj');

        $this->assertNull(PjConversion::status($seller->fresh()));
        $this->assertSame('pf', $seller->fresh()->person_type);
    }

    public function test_approved_account_cannot_upload_company_docs_without_starting_conversion(): void
    {
        Storage::fake('local');
        $seller = $this->createApprovedPfSeller();

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'company_address_proof',
                'company_address_proof' => $this->img('addr.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_conversion_allows_company_docs_and_keeps_kyc_approved_after_submit(): void
    {
        Storage::fake('local');
        $seller = $this->createApprovedPfSeller();

        $this->actingAs($seller)
            ->post(route('profile.pj-conversion.start'), [
                'cnpj' => '11222333000181',
                'company_name' => 'Empresa Convertida LTDA',
                'company_legal_nature' => 'other',
            ])
            ->assertRedirect();

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'company_address_proof',
                'company_legal_nature' => 'other',
                'company_address_proof' => $this->img('addr.jpg'),
            ])
            ->assertOk();

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'social_contract',
                'company_legal_nature' => 'other',
                'social_contract' => $this->img('contrato.jpg'),
            ])
            ->assertOk();

        $this->actingAs($seller)
            ->post(route('kyc.finalize'), [
                'company_legal_nature' => 'other',
            ])
            ->assertRedirect(route('profile.index'));

        $seller->refresh();
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
        $this->assertTrue($seller->isMerchantOperationallyApproved());
        $this->assertSame('pf', $seller->person_type);
        $this->assertSame(PjConversion::STATUS_PENDING_REVIEW, PjConversion::status($seller));
        $this->assertTrue((bool) $seller->kyc_needs_document_review);
    }

    public function test_admin_approval_migrates_account_to_pj_keeping_cpf_as_representative(): void
    {
        Storage::fake('local');
        $admin = $this->seedPlatformAdmin();
        $seller = $this->createApprovedPfSeller();

        $this->actingAs($seller)->post(route('profile.pj-conversion.start'), [
            'cnpj' => '11222333000181',
            'company_name' => 'Empresa Convertida LTDA',
            'company_legal_nature' => 'other',
        ]);

        foreach ([KycDocument::KIND_COMPANY_ADDRESS_PROOF, KycDocument::KIND_SOCIAL_CONTRACT] as $kind) {
            KycDocument::query()->create([
                'user_id' => $seller->id,
                'kind' => $kind,
                'disk_path' => 'kyc/test/'.$kind.'.jpg',
                'original_mime' => 'image/jpeg',
                'size_bytes' => 1000,
                'public_token' => 'tok-'.$kind,
            ]);
        }

        $this->actingAs($seller)->post(route('kyc.finalize'), [
            'company_legal_nature' => 'other',
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.approve-pj-conversion', $seller))
            ->assertRedirect(route('plataforma.kyc.show', $seller));

        $seller->refresh();
        $this->assertSame('pj', $seller->person_type);
        $this->assertSame('11222333000181', $seller->document);
        $this->assertSame('39053344705', $seller->legal_representative_cpf);
        $this->assertSame('Empresa Convertida LTDA', $seller->company_name);
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
        $this->assertTrue($seller->isMerchantOperationallyApproved());
        $this->assertFalse((bool) $seller->kyc_needs_document_review);
        $this->assertNull(PjConversion::status($seller));
    }

    public function test_admin_rejection_keeps_pf_account_operational(): void
    {
        $admin = $this->seedPlatformAdmin();
        $seller = $this->createApprovedPfSeller();

        $this->actingAs($seller)->post(route('profile.pj-conversion.start'), [
            'cnpj' => '11222333000181',
            'company_name' => 'Empresa Convertida LTDA',
            'company_legal_nature' => 'mei',
        ]);

        foreach ([KycDocument::KIND_COMPANY_ADDRESS_PROOF, KycDocument::KIND_CCMEI] as $kind) {
            KycDocument::query()->create([
                'user_id' => $seller->id,
                'kind' => $kind,
                'disk_path' => 'kyc/test/'.$kind.'.jpg',
                'original_mime' => 'image/jpeg',
                'size_bytes' => 1000,
                'public_token' => 'tok-'.$kind,
            ]);
        }

        $this->actingAs($seller)->post(route('kyc.finalize'), [
            'company_legal_nature' => 'mei',
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.reject-pj-conversion', $seller), [
                'reason' => 'Contrato ilegível.',
            ])
            ->assertRedirect(route('plataforma.kyc.show', $seller));

        $seller->refresh();
        $this->assertSame('pf', $seller->person_type);
        $this->assertSame('39053344705', $seller->document);
        $this->assertNull($seller->legal_representative_cpf);
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
        $this->assertTrue($seller->isMerchantOperationallyApproved());
        $this->assertSame(PjConversion::STATUS_REJECTED, PjConversion::status($seller));
        $this->assertFalse((bool) $seller->kyc_needs_document_review);
    }

    public function test_profile_exposes_conversion_button_only_for_approved_pf(): void
    {
        $seller = $this->createApprovedPfSeller();

        $this->actingAs($seller)
            ->get(route('profile.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Index')
                ->where('pj_conversion_eligible', true)
                ->where('pj_conversion', null)
            );
    }
}
