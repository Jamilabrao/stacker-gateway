<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberBuilderReorderTest extends TestCase
{
    public function test reorder_sections_updates_positions_atomically(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'mbreor'.substr(uniqid('', true), -8),
            'slug' => 'mp-'.substr(uniqid('', true), -8),
        ]);

        $s1 = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'A',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $s2 = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'B',
            'position' => 2,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $resp = $this->actingAs($owner)->postJson(route('member-builder.reorder', $product), [
            'scope' => 'sections',
            'ordered_ids' => [$s2->id, $s1->id],
        ]);

        $resp->assertOk();
        $this->assertSame(1, $s2->fresh()->position);
        $this->assertSame(2, $s1->fresh()->position);
    }

    public function test reorder_modules_requires_valid_section_and_full_id_set(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'mbmod'.substr(uniqid('', true), -8),
            'slug' => 'mm-'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'S',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $m1 = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'M1',
            'position' => 1,
        ]);
        $m2 = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'M2',
            'position' => 2,
        ]);

        $resp = $this->actingAs($owner)->postJson(route('member-builder.reorder', $product), [
            'scope' => 'modules',
            'section_id' => $section->id,
            'ordered_ids' => [$m2->id, $m1->id],
        ]);

        $resp->assertOk();
        $this->assertSame(1, $m2->fresh()->position);
        $this->assertSame(2, $m1->fresh()->position);

        // Falta um ID ⇒ 422
        $respBad = $this->actingAs($owner)->postJson(route('member-builder.reorder', $product), [
            'scope' => 'modules',
            'section_id' => $section->id,
            'ordered_ids' => [$m1->id],
        ]);
        $respBad->assertUnprocessable();
    }

    public function test reorder_lessons_within_module(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'mbles'.substr(uniqid('', true), -8),
            'slug' => 'ml-'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'S',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Mod',
            'position' => 1,
        ]);

        $l1 = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'L1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
        ]);
        $l2 = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'L2',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
        ]);

        $resp = $this->actingAs($owner)->postJson(route('member-builder.reorder', $product), [
            'scope' => 'lessons',
            'module_id' => $module->id,
            'ordered_ids' => [$l2->id, $l1->id],
        ]);

        $resp->assertOk();
        $this->assertSame(1, $l2->fresh()->position);
        $this->assertSame(2, $l1->fresh()->position);
    }
}
