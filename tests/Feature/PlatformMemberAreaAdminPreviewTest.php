<?php

namespace Tests\Feature;

use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\PlatformAuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformMemberAreaAdminPreviewTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function sellerWithMemberArea(): array
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'name' => 'Seller Área',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $slug = 'mapa'.substr(uniqid('', true), -8);
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'name' => 'Curso Preview Admin',
            'checkout_slug' => $slug,
            'slug' => 'p-'.$slug,
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
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => 'Conteúdo de moderação',
        ]);

        return [
            'seller' => $seller->fresh(),
            'product' => $product->fresh(),
            'lesson' => $lesson,
        ];
    }

    public function test_admin_preview_route_redirects_to_member_area_and_audits(): void
    {
        $admin = $this->platformAdmin();
        ['product' => $product] = $this->sellerWithMemberArea();

        $response = $this->actingAs($admin)
            ->get(route('plataforma.produtos.member-area.preview', $product));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotEmpty($location);
        $this->assertStringContainsString('/m/'.$product->checkout_slug, $location);

        $this->assertTrue(
            PlatformAuditLog::query()
                ->where('action', 'platform.product.member_area_previewed')
                ->where('user_id', $admin->id)
                ->exists()
        );

        $this->assertDatabaseMissing('product_user', [
            'product_id' => $product->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_open_member_area_without_enrollment_or_checkout(): void
    {
        $admin = $this->platformAdmin();
        ['product' => $product] = $this->sellerWithMemberArea();

        $response = $this->actingAs($admin)
            ->get(route('member-area-app.show', ['slug' => $product->checkout_slug]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('MemberAreaApp/Show')
            ->where('member_area_admin_preview.active', true)
            ->where('member_area_admin_preview.read_only', true)
            ->where('slug', $product->checkout_slug)
        );

        $this->assertSame(0, (int) DB::table('product_user')->where('product_id', $product->id)->where('user_id', $admin->id)->count());
        $this->assertSame(0, MemberLessonProgress::query()->where('user_id', $admin->id)->count());
    }

    public function test_admin_cannot_complete_lesson_and_no_progress_is_created(): void
    {
        $admin = $this->platformAdmin();
        ['product' => $product, 'lesson' => $lesson] = $this->sellerWithMemberArea();

        $response = $this->actingAs($admin)
            ->postJson(route('member-area-app.lesson.complete', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
            ]));

        $response->assertForbidden();
        $this->assertSame(0, MemberLessonProgress::query()->where('user_id', $admin->id)->count());
        $this->assertSame(0, (int) DB::table('product_user')->where('product_id', $product->id)->where('user_id', $admin->id)->count());
    }

    public function test_client_without_access_still_redirects_to_checkout(): void
    {
        ['product' => $product] = $this->sellerWithMemberArea();
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($client)
            ->get(route('member-area-app.show', ['slug' => $product->checkout_slug]));

        $response->assertRedirect(route('checkout.show', ['slug' => $product->checkout_slug]));
    }

    public function test_seller_owner_still_has_member_area_access(): void
    {
        ['seller' => $seller, 'product' => $product] = $this->sellerWithMemberArea();

        $response = $this->actingAs($seller)
            ->get(route('member-area-app.show', ['slug' => $product->checkout_slug]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('MemberAreaApp/Show')
            ->where('member_area_admin_preview', null)
        );
    }

    public function test_preview_rejects_non_member_area_product(): void
    {
        $admin = $this->platformAdmin();
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_LINK,
            'checkout_slug' => 'linkp'.substr(uniqid('', true), -8),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('plataforma.produtos.member-area.preview', $product));

        $response->assertRedirect(route('plataforma.produtos.index'));
        $response->assertSessionHas('error');
    }
}
