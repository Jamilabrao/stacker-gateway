<?php

namespace Tests\Feature;

use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberProgressService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberCertificateReleaseTest extends TestCase
{
    public function test_certificate_eligible_by_days_after_access_without_completion(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $student = User::factory()->create(['role' => User::ROLE_ALUNO]);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'release_mode' => MemberProgressService::CERT_RELEASE_DAYS,
                    'completion_percent' => 100,
                    'days_after_access' => 7,
                ],
            ]),
        ]);

        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo 1',
            'position' => 1,
        ]);
        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
        ]);

        $ps = app(MemberProgressService::class);
        $eligibility = $ps->certificateEligibility($product, $student);

        $this->assertTrue($eligibility['eligible']);
        $this->assertTrue($eligibility['days_met']);
        $this->assertFalse($eligibility['percent_met']);
        $this->assertTrue($ps->canIssueCertificate($product, $student));
    }

    public function test_certificate_requires_both_percent_and_days(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $student = User::factory()->create(['role' => User::ROLE_ALUNO]);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'release_mode' => MemberProgressService::CERT_RELEASE_BOTH,
                    'completion_percent' => 50,
                    'days_after_access' => 3,
                ],
            ]),
        ]);

        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo 1',
            'position' => 1,
        ]);
        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
        ]);
        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 2',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
        ]);

        $ps = app(MemberProgressService::class);
        $this->assertFalse($ps->canIssueCertificate($product, $student));

        foreach (MemberLesson::query()->where('product_id', $product->id)->orderBy('position')->get() as $lesson) {
            $ps->markLessonCompleted($lesson->id, $student);
        }

        $this->assertTrue($ps->canIssueCertificate($product, $student));
    }
}
