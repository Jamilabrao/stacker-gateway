<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Jobs\SendAffiliateEnrollmentApprovedMailJob;
use App\Models\PanelNotification;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliateEnrollmentFeedbackTest extends TestCase
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
            'email' => 'affiliate-'.uniqid('', true).'@test.com',
        ]);
        $affiliate->forceFill([
            'tenant_id' => $affiliate->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $affiliate->fresh();
    }

    private function createAffiliateProduct(User $seller, array $overrides = []): Product
    {
        if (! Schema::hasColumn('products', 'affiliate_enabled')) {
            $this->markTestSkipped('affiliate columns');
        }

        return $this->createTestProduct(array_merge([
            'tenant_id' => $seller->id,
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 20,
            'affiliate_manual_approval' => true,
            'affiliate_show_in_showcase' => true,
            'checkout_slug' => 'aff-'.uniqid('', true),
            'is_active' => true,
        ], $overrides));
    }

    public function test_showcase_enroll_creates_pending_enrollment_with_flash(): void
    {
        $seller = $this->createSeller();
        $affiliate = $this->createAffiliate();
        $product = $this->createAffiliateProduct($seller);

        $response = $this->actingAs($affiliate)
            ->post(route('produtos.vitrine-afiliacao.solicitar', $product->id));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Solicitação enviada ao produtor.');

        $this->assertDatabaseHas('product_affiliate_enrollments', [
            'product_id' => $product->id,
            'affiliate_user_id' => $affiliate->id,
            'status' => ProductAffiliateEnrollment::STATUS_PENDING,
        ]);
    }

    public function test_showcase_enroll_auto_approves_when_manual_approval_disabled(): void
    {
        $seller = $this->createSeller();
        $affiliate = $this->createAffiliate();
        $product = $this->createAffiliateProduct($seller, [
            'affiliate_manual_approval' => false,
        ]);

        Queue::fake();

        $response = $this->actingAs($affiliate)
            ->post(route('produtos.vitrine-afiliacao.solicitar', $product->id));

        $response->assertSessionHas('success', 'Você foi aprovado como afiliado.');

        $enrollment = ProductAffiliateEnrollment::query()
            ->where('product_id', $product->id)
            ->where('affiliate_user_id', $affiliate->id)
            ->first();

        $this->assertNotNull($enrollment);
        $this->assertSame(ProductAffiliateEnrollment::STATUS_APPROVED, $enrollment->status);
        $this->assertNotEmpty($enrollment->public_ref);

        Queue::assertPushed(SendAffiliateEnrollmentApprovedMailJob::class, function ($job) use ($enrollment) {
            return $job->enrollmentId === $enrollment->id;
        });
    }

    public function test_approve_enrollment_notifies_affiliate_and_queues_email(): void
    {
        $seller = $this->createSeller();
        $affiliate = $this->createAffiliate();
        $product = $this->createAffiliateProduct($seller);

        $enrollment = ProductAffiliateEnrollment::query()->create([
            'product_id' => $product->id,
            'affiliate_user_id' => $affiliate->id,
            'status' => ProductAffiliateEnrollment::STATUS_PENDING,
        ]);

        Queue::fake();

        $this->actingAs($seller)
            ->post(route('afiliados.enrollments.approve', $enrollment->id))
            ->assertRedirect();

        $enrollment->refresh();
        $this->assertSame(ProductAffiliateEnrollment::STATUS_APPROVED, $enrollment->status);
        $this->assertNotEmpty($enrollment->public_ref);

        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $affiliate->id,
            'event_key' => 'affiliate_enrollment_'.$enrollment->id,
            'type' => 'affiliate_enrollment_approved',
        ]);

        Queue::assertPushed(SendAffiliateEnrollmentApprovedMailJob::class, function ($job) use ($enrollment) {
            return $job->enrollmentId === $enrollment->id;
        });

        app(\App\Services\AffiliateEnrollmentNotifier::class)->notifyApproved($enrollment->fresh());

        $this->assertSame(
            1,
            PanelNotification::query()
                ->where('user_id', $affiliate->id)
                ->where('event_key', 'affiliate_enrollment_'.$enrollment->id)
                ->count()
        );
    }
}
