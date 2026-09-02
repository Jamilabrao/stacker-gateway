<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliateJoinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    private function createSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller->fresh();
    }

    private function createAffiliate(): User
    {
        $affiliate = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'affiliate-join-'.uniqid('', true).'@test.com',
        ]);
        $affiliate->forceFill([
            'tenant_id' => $affiliate->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $affiliate->fresh();
    }

    private function createPrivateAffiliateProduct(User $seller, array $overrides = []): Product
    {
        if (! Schema::hasColumn('products', 'affiliate_enabled')
            || ! Schema::hasColumn('products', 'affiliate_invite_token')) {
            $this->markTestSkipped('affiliate invite token');
        }

        $product = $this->createTestProduct(array_merge([
            'tenant_id' => $seller->id,
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 20,
            'affiliate_manual_approval' => true,
            'affiliate_show_in_showcase' => false,
            'checkout_slug' => 'j'.substr(md5(uniqid('', true)), 0, 15),
            'is_active' => true,
        ], $overrides));

        $product->ensureAffiliateInviteToken();

        return $product->fresh();
    }

    public function test_enabling_affiliation_generates_join_token(): void
    {
        if (! Schema::hasColumn('products', 'affiliate_invite_token')) {
            $this->markTestSkipped('affiliate invite token');
        }

        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);

        $seller = $this->createSeller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'affiliate_enabled' => false,
            'affiliate_show_in_showcase' => false,
            'is_active' => true,
        ]);

        $this->actingAs($seller)->put(route('produtos.affiliate-settings.update', $product), [
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 15,
            'affiliate_manual_approval' => true,
            'affiliate_show_in_showcase' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertNotEmpty($product->affiliate_invite_token);
        $this->assertNotNull($product->affiliateJoinUrl());
    }

    public function test_showcase_enroll_is_blocked_when_product_is_hidden(): void
    {
        $seller = $this->createSeller();
        $affiliate = $this->createAffiliate();
        $product = $this->createPrivateAffiliateProduct($seller);

        $this->actingAs($affiliate)
            ->post(route('produtos.vitrine-afiliacao.solicitar', $product->id))
            ->assertRedirect()
            ->assertSessionHas('error', 'Este produto não está disponível para afiliação.');

        $this->assertDatabaseMissing('product_affiliate_enrollments', [
            'product_id' => $product->id,
            'affiliate_user_id' => $affiliate->id,
        ]);
    }

    public function test_guest_can_open_join_page_for_hidden_product(): void
    {
        $seller = $this->createSeller();
        $product = $this->createPrivateAffiliateProduct($seller, ['name' => 'Curso Privado Join']);

        $this->get(route('affiliate.join.show', ['token' => $product->affiliate_invite_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Produtos/Afiliar')
                ->where('invalid', false)
                ->where('program_open', true)
                ->where('is_guest', true)
                ->where('can_request', false)
                ->where('product.name', 'Curso Privado Join'));
    }

    public function test_join_enroll_works_without_showcase(): void
    {
        $seller = $this->createSeller();
        $affiliate = $this->createAffiliate();
        $product = $this->createPrivateAffiliateProduct($seller);

        $this->actingAs($affiliate)
            ->post(route('affiliate.join.enroll', ['token' => $product->affiliate_invite_token]))
            ->assertRedirect(route('affiliate.join.show', ['token' => $product->affiliate_invite_token]))
            ->assertSessionHas('success', 'Solicitação enviada ao produtor.');

        $this->assertDatabaseHas('product_affiliate_enrollments', [
            'product_id' => $product->id,
            'affiliate_user_id' => $affiliate->id,
            'status' => ProductAffiliateEnrollment::STATUS_PENDING,
        ]);
    }

    public function test_join_enroll_auto_approves_when_manual_approval_disabled(): void
    {
        $seller = $this->createSeller();
        $affiliate = $this->createAffiliate();
        $product = $this->createPrivateAffiliateProduct($seller, [
            'affiliate_manual_approval' => false,
        ]);

        $this->actingAs($affiliate)
            ->post(route('affiliate.join.enroll', ['token' => $product->affiliate_invite_token]))
            ->assertSessionHas('success', 'Você foi aprovado como afiliado.');

        $enrollment = ProductAffiliateEnrollment::query()
            ->where('product_id', $product->id)
            ->where('affiliate_user_id', $affiliate->id)
            ->first();

        $this->assertNotNull($enrollment);
        $this->assertSame(ProductAffiliateEnrollment::STATUS_APPROVED, $enrollment->status);
        $this->assertNotEmpty($enrollment->public_ref);
    }

    public function test_owner_cannot_join_own_product(): void
    {
        $seller = $this->createSeller();
        $product = $this->createPrivateAffiliateProduct($seller);

        $this->actingAs($seller)
            ->post(route('affiliate.join.enroll', ['token' => $product->affiliate_invite_token]))
            ->assertSessionHas('error', 'Você não pode se afiliar ao próprio produto.');
    }

    public function test_unknown_token_shows_invalid_page(): void
    {
        if (! Schema::hasColumn('products', 'affiliate_invite_token')) {
            $this->markTestSkipped('affiliate invite token');
        }

        $this->get(route('affiliate.join.show', ['token' => str_repeat('a', 40)]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Produtos/Afiliar')
                ->where('invalid', true));
    }

    public function test_regenerating_token_invalidates_previous_link(): void
    {
        $seller = $this->createSeller();
        $affiliate = $this->createAffiliate();
        $product = $this->createPrivateAffiliateProduct($seller);
        $oldToken = $product->affiliate_invite_token;

        $this->actingAs($seller)
            ->post(route('produtos.affiliate-invite-token.regenerate', $product).'?tab=afiliados')
            ->assertRedirect();

        $product->refresh();
        $this->assertNotSame($oldToken, $product->affiliate_invite_token);

        $this->actingAs($affiliate)
            ->post(route('affiliate.join.enroll', ['token' => $oldToken]))
            ->assertSessionHas('error', 'Este link de afiliação não é válido.');

        $this->actingAs($affiliate)
            ->post(route('affiliate.join.enroll', ['token' => $product->affiliate_invite_token]))
            ->assertSessionHas('success', 'Solicitação enviada ao produtor.');
    }
}
