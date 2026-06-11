<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberCommunityPage;
use App\Models\MemberInternalProduct;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberBuilderCommunityPageTest extends TestCase
{
    public function test_can_create_two_community_pages_with_same_title_using_unique_slugs(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mbcom'.substr(uniqid('', true), -8),
            'slug' => 'mc-'.substr(uniqid('', true), -8),
        ]);

        $first = $this->actingAs($owner)->postJson(
            route('member-builder.community-pages.store', ['produto' => $product->id]),
            ['title' => 'Geral', 'is_public_posting' => true]
        );
        $first->assertOk();
        $first->assertJsonPath('community_pages.0.slug', 'geral');

        $second = $this->actingAs($owner)->postJson(
            route('member-builder.community-pages.store', ['produto' => $product->id]),
            ['title' => 'Geral', 'is_public_posting' => true]
        );
        $second->assertOk();
        $second->assertJsonPath('community_pages.1.slug', 'geral-2');
        $this->assertCount(2, $second->json('community_pages'));
    }

    public function test_member_area_internal_products_include_checkout_url(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        $memberProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mbsh'.substr(uniqid('', true), -8),
            'slug' => 'ms-'.substr(uniqid('', true), -8),
        ]);

        $related = $this->createTestProduct([
            'tenant_id' => $owner->id,
            'checkout_slug' => 'relprd'.substr(uniqid('', true), -6),
            'slug' => 'rp-'.substr(uniqid('', true), -8),
            'price' => 99,
        ]);

        MemberInternalProduct::create([
            'product_id' => $memberProduct->id,
            'related_product_id' => $related->id,
            'position' => 1,
        ]);

        $student = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        $memberProduct->users()->attach($student->id);

        $response = $this->actingAs($student)->get(route('member-area-app.show', ['slug' => $memberProduct->checkout_slug]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('internal_products', 1)
            ->where('internal_products.0.checkout_url', url('/c/'.$related->checkout_slug))
            ->where('internal_products.0.checkout_slug', $related->checkout_slug)
        );
    }

    public function test_unique_slug_helper_handles_empty_title_characters(): void
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'mbem'.substr(uniqid('', true), -8),
        ]);

        $slug = MemberCommunityPage::uniqueSlugForProduct($product->id, '!!!');

        $this->assertNotSame('', $slug);
        $this->assertMatchesRegularExpression('/^page-[a-z0-9]+$/', $slug);
    }
}
