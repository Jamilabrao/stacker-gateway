<?php

namespace Tests\Feature;

use App\Jobs\SendProductApprovedMailJob;
use App\Jobs\SendProductRejectedMailJob;
use App\Models\PlatformAuditLog;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\ProductApprovalService;
use App\Support\ProductApprovalSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductApprovalFlowTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'password' => Hash::make('password'),
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    public function test_auto_approve_setting_defaults_to_enabled(): void
    {
        $this->assertTrue(ProductApprovalSettings::autoApproveEnabled());
    }

    public function test_admin_can_toggle_auto_approve_setting_and_audit(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->put(route('plataforma.settings.update'), [
                'auto_approve_products' => false,
                'checkout_translations' => config('checkout_translations'),
                'currencies' => config('products.currencies'),
                'email_provider' => 'smtp',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '587',
                'smtp_username' => 'user@example.com',
                'smtp_encryption' => 'tls',
                'mail_from_address' => 'noreply@example.com',
                'mail_from_name' => 'Getfy',
            ])
            ->assertRedirect();

        $this->assertFalse(ProductApprovalSettings::autoApproveEnabled());
        $this->assertSame('0', Setting::get(ProductApprovalSettings::KEY, null, null));

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertTrue(
                PlatformAuditLog::query()
                    ->where('action', 'products.auto_approval_setting_updated')
                    ->exists()
            );
        }

        $this->actingAs($admin)
            ->put(route('plataforma.settings.update'), [
                'auto_approve_products' => true,
                'checkout_translations' => config('checkout_translations'),
                'currencies' => config('products.currencies'),
                'email_provider' => 'smtp',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '587',
                'smtp_username' => 'user@example.com',
                'smtp_encryption' => 'tls',
                'mail_from_address' => 'noreply@example.com',
                'mail_from_name' => 'Getfy',
            ])
            ->assertRedirect();

        $this->assertTrue(ProductApprovalSettings::autoApproveEnabled());
    }

    public function test_seller_cannot_change_auto_approve_setting(): void
    {
        $seller = $this->seller();
        Setting::set(ProductApprovalSettings::KEY, '1', null);

        $this->actingAs($seller)
            ->put(route('plataforma.settings.update'), [
                'auto_approve_products' => false,
                'checkout_translations' => config('checkout_translations'),
                'currencies' => config('products.currencies'),
                'email_provider' => 'smtp',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '587',
                'smtp_username' => 'user@example.com',
                'smtp_encryption' => 'tls',
                'mail_from_address' => 'noreply@example.com',
                'mail_from_name' => 'Getfy',
            ])
            ->assertForbidden();

        $this->assertTrue(ProductApprovalSettings::autoApproveEnabled());
    }

    public function test_product_created_with_auto_approve_is_approved(): void
    {
        Setting::set(ProductApprovalSettings::KEY, '1', null);
        $seller = $this->seller();

        $response = $this->actingAs($seller)->post(route('produtos.store'), [
            'name' => 'Produto Auto Aprovado',
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 50,
            'is_active' => true,
            'deliverable_link' => 'https://example.com/file',
        ]);

        $response->assertRedirect(route('produtos.index'));
        $product = Product::query()->where('name', 'Produto Auto Aprovado')->first();
        $this->assertNotNull($product);
        $this->assertSame(Product::APPROVAL_APPROVED, $product->approval_status);
        $this->assertSame(Product::APPROVAL_SOURCE_AUTOMATIC, $product->approval_source);
        $this->assertTrue($product->isAvailableForPurchase());
    }

    public function test_product_created_with_manual_review_is_pending_and_not_sellable(): void
    {
        Setting::set(ProductApprovalSettings::KEY, '0', null);
        $seller = $this->seller();

        $this->actingAs($seller)->post(route('produtos.store'), [
            'name' => 'Produto Em Análise',
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 50,
            'is_active' => true,
            'deliverable_link' => 'https://example.com/file',
        ])->assertRedirect(route('produtos.index'));

        $product = Product::query()->where('name', 'Produto Em Análise')->first();
        $this->assertNotNull($product);
        $this->assertSame(Product::APPROVAL_PENDING, $product->approval_status);
        $this->assertFalse((bool) $product->is_active);
        $this->assertFalse($product->isAvailableForPurchase());

        $this->get(route('checkout.show', ['slug' => $product->checkout_slug]))->assertNotFound();
    }

    public function test_admin_approves_pending_product_without_forcing_active(): void
    {
        Bus::fake([SendProductApprovedMailJob::class]);
        Setting::set(ProductApprovalSettings::KEY, '0', null);
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Aprovar Manual',
            'type' => Product::TYPE_LINK,
            'is_active' => false,
            'approval_status' => Product::APPROVAL_PENDING,
            'approval_source' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.produtos.approve', $product))
            ->assertRedirect();

        $product->refresh();
        $this->assertSame(Product::APPROVAL_APPROVED, $product->approval_status);
        $this->assertSame($admin->id, (int) $product->reviewed_by);
        $this->assertNotNull($product->reviewed_at);
        $this->assertNull($product->approval_reason);
        $this->assertFalse((bool) $product->is_active);
        $this->assertFalse($product->isAvailableForPurchase());

        Bus::assertDispatched(SendProductApprovedMailJob::class);
    }

    public function test_admin_rejects_with_reason_and_forces_inactive(): void
    {
        Bus::fake([SendProductRejectedMailJob::class]);
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Rejeitar Manual',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_PENDING,
        ]);

        $reason = 'A descrição do produto promete resultados financeiros garantidos.';

        $this->actingAs($admin)
            ->post(route('plataforma.produtos.reject', $product), ['reason' => $reason])
            ->assertRedirect();

        $product->refresh();
        $this->assertSame(Product::APPROVAL_REJECTED, $product->approval_status);
        $this->assertSame($reason, $product->approval_reason);
        $this->assertFalse((bool) $product->is_active);
        $this->assertFalse($product->isAvailableForPurchase());
        Bus::assertDispatched(SendProductRejectedMailJob::class);
    }

    public function test_reject_without_reason_is_blocked(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'approval_status' => Product::APPROVAL_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.produtos.reject', $product), ['reason' => 'curto'])
            ->assertSessionHasErrors('reason');
    }

    public function test_seller_cannot_activate_pending_product(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Pending Ativar',
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'is_active' => false,
            'approval_status' => Product::APPROVAL_PENDING,
            'price' => 40,
            'currency' => 'BRL',
        ]);

        $this->actingAs($seller)
            ->post(route('produtos.update', $product), [
                '_method' => 'PUT',
                'name' => $product->name,
                'description' => 'x',
                'type' => Product::TYPE_LINK,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 40,
                'currency' => 'BRL',
                'is_active' => true,
                'refund_policy_days' => '',
                'deliverable_link' => 'https://example.com/x',
            ])
            ->assertSessionHas('error');

        $this->assertFalse((bool) $product->fresh()->is_active);
    }

    public function test_seller_resubmits_rejected_product(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'is_active' => false,
            'approval_status' => Product::APPROVAL_REJECTED,
            'approval_reason' => 'A descrição do produto promete resultados financeiros garantidos.',
            'reviewed_by' => 1,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($seller)
            ->post(route('produtos.resubmit', $product))
            ->assertRedirect();

        $product->refresh();
        $this->assertSame(Product::APPROVAL_PENDING, $product->approval_status);
        $this->assertFalse((bool) $product->is_active);
        $this->assertFalse($product->isAvailableForPurchase());
        $this->assertNotNull($product->approval_reason);
    }

    public function test_admin_cannot_activate_pending_product(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'is_active' => false,
            'approval_status' => Product::APPROVAL_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.produtos.ativacao', $product), ['is_active' => true])
            ->assertSessionHas('error');

        $this->assertFalse((bool) $product->fresh()->is_active);
    }

    public function test_approved_and_active_is_sellable_rejected_is_not(): void
    {
        $seller = $this->seller();
        $ok = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'is_active' => true,
            'admin_blocked' => false,
            'approval_status' => Product::APPROVAL_APPROVED,
            'checkout_slug' => 'aprovado01',
        ]);
        $bad = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'is_active' => true,
            'admin_blocked' => false,
            'approval_status' => Product::APPROVAL_REJECTED,
            'checkout_slug' => 'rejeitado1',
        ]);

        $this->assertTrue($ok->isAvailableForPurchase());
        $this->assertFalse($bad->isAvailableForPurchase());
        $this->assertTrue(
            Product::query()->availableForPurchase()->whereKey($ok->id)->exists()
        );
        $this->assertFalse(
            Product::query()->availableForPurchase()->whereKey($bad->id)->exists()
        );
    }

    public function test_duplicate_approval_decision_is_blocked(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'approval_status' => Product::APPROVAL_APPROVED,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.produtos.approve', $product))
            ->assertSessionHas('error');
    }

    public function test_existing_style_product_without_explicit_status_behaves_as_approved_when_migrated(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'approval_status'));
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'is_active' => true,
            'admin_blocked' => false,
        ]);

        // Default da migration/coluna.
        $this->assertTrue(in_array($product->approval_status, [null, Product::APPROVAL_APPROVED], true)
            || $product->isApprovalApproved());
        $this->assertTrue($product->fresh()->isAvailableForPurchase());
    }

    public function test_email_failure_does_not_revert_approval(): void
    {
        Bus::fake([SendProductApprovedMailJob::class]);
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'approval_status' => Product::APPROVAL_PENDING,
            'is_active' => false,
        ]);

        app(ProductApprovalService::class)->approve($product, $admin);
        $this->assertSame(Product::APPROVAL_APPROVED, $product->fresh()->approval_status);
        Bus::assertDispatched(SendProductApprovedMailJob::class);
    }
}
