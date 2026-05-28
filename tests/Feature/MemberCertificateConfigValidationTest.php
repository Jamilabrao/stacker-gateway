<?php

namespace Tests\Feature;

use App\Models\BrandingSetting;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberCertificateConfigValidationTest extends TestCase
{
    public function test_member_area_certificate_payload_always_uses_global_platform_name(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $student = User::factory()->create(['role' => User::ROLE_ALUNO]);

        BrandingSetting::query()->updateOrCreate(
            ['tenant_id' => null],
            ['data' => ['app_name' => 'Plataforma Global Teste']]
        );

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'platform_name' => 'Nome customizado do produto',
                ],
            ]),
        ]);
        $product->users()->syncWithoutDetaching([$student->id]);

        $this->actingAs($student)
            ->get(route('member-area-app.certificado', ['slug' => $product->checkout_slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificate.platform_name', 'Plataforma Global Teste')
                ->where('certificate.header_text', 'Certificado de conclusão')
            );
    }

    public function test_member_builder_requires_duration_when_certificate_enabled(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'name' => 'Curso Teste Certificado',
        ]);

        $payload = [
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'title' => '',
                    'signature_text' => '',
                    'duration_text' => '',
                ],
            ]),
        ];

        $this->actingAs($seller)
            ->postJson(route('member-builder.config.update.post', ['produto' => $product->id]), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member_area_config.certificate.duration_text']);
    }

    public function test_member_builder_saves_certificate_when_enabled_with_auto_defaults(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'name' => 'Curso Salvar Certificado',
        ]);

        $payload = [
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'title' => '',
                    'signature_text' => '',
                    'duration_text' => '40 horas',
                ],
            ]),
        ];

        $this->actingAs($seller)
            ->postJson(route('member-builder.config.update.post', ['produto' => $product->id]), $payload)
            ->assertOk();

        $product->refresh();
        $cert = $product->member_area_config['certificate'] ?? [];

        $this->assertTrue((bool) ($cert['enabled'] ?? false));
        $this->assertSame('Curso Salvar Certificado', $cert['title']);
        $this->assertSame('Instrutor', $cert['signature_text']);
        $this->assertSame('Certificado de conclusão', $cert['header_text']);
    }
}

