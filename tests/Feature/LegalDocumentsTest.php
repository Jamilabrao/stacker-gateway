<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\LegalDocumentsService;
use App\Support\LegalDocumentDefaults;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegalDocumentsTest extends TestCase
{
    public function test_privacy_page_returns_default_content(): void
    {
        $response = $this->get(route('legal.privacy'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Legal/Privacy')
            ->has('html'));

        $this->assertStringContainsString('Política de Privacidade', LegalDocumentDefaults::privacyPolicyHtml());
    }

    public function test_terms_page_returns_default_content(): void
    {
        $response = $this->get(route('legal.terms'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Legal/Terms'));
    }

    public function test_custom_privacy_html_is_shown(): void
    {
        Setting::set(LegalDocumentsService::SETTING_PRIVACY_HTML, '<h1>Política</h1><p>Texto customizado LGPD teste.</p>', null);

        $html = app(LegalDocumentsService::class)->sanitizedPrivacyHtml();
        $this->assertStringContainsString('Texto customizado LGPD teste', $html);
    }

    public function test_registration_requires_terms_acceptance(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);

        $payload = $this->validRegistrationPayload();
        unset($payload['accept_terms_privacy']);

        $response = $this->post('/cadastro', $payload);

        $response->assertSessionHasErrors('accept_terms_privacy');
    }

    public function test_registration_records_legal_consent(): void
    {
        if (! Schema::hasColumn('users', 'privacy_policy_accepted_at')) {
            $this->markTestSkipped('legal consent columns');
        }

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);

        $payload = $this->validRegistrationPayload();
        $payload['accept_terms_privacy'] = '1';

        $response = $this->post('/cadastro', $payload);

        $response->assertRedirect();

        $user = User::query()->where('email', $payload['email'])->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->privacy_policy_accepted_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotEmpty($user->legal_consent_version);
    }

    public function test_platform_admin_can_save_legal_settings(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->put(route('plataforma.settings.update'), [
            'legal_privacy_contact_email' => 'dpo@teste.local',
            'legal_privacy_policy_html' => '<h1>Política</h1><p>Admin custom.</p>',
            'legal_terms_of_use_html' => '<h1>Termos</h1><p>Termos admin.</p>',
            'legal_cookie_banner_enabled' => true,
        ]);

        $response->assertRedirect();

        $this->assertSame('dpo@teste.local', Setting::get(LegalDocumentsService::SETTING_PRIVACY_EMAIL, null, null));
        $stored = (string) Setting::get(LegalDocumentsService::SETTING_PRIVACY_HTML, '', null);
        $this->assertStringContainsString('Admin custom', $stored);
    }

    /**
     * @return array<string, mixed>
     */
    private function validRegistrationPayload(): array
    {
        $uniq = uniqid('legal', true);

        return [
            'person_type' => 'pf',
            'name' => 'Titular Teste',
            'email' => "legal.{$uniq}@example.com",
            'birth_date' => '1990-01-15',
            'document' => '52998224725',
            'address_zip' => '01310100',
            'address_street' => 'Av Paulista',
            'address_number' => '1000',
            'address_neighborhood' => 'Bela Vista',
            'address_city' => 'São Paulo',
            'address_state' => 'SP',
            'monthly_revenue_range' => 'up_to_10k',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
