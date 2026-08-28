<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PlatformAuditLog;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AdminSettlementAnticipateService;
use App\Services\Platform\PlatformTotpService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GeneratesTotpCodes;
use Tests\TestCase;

class PlatformAdminAnticipateSettlementTest extends TestCase
{
    use GeneratesTotpCodes;

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function platformAdminWithTotp(): array
    {
        $admin = $this->platformAdmin();
        $setup = PlatformTotpService::beginEnrollment($admin->fresh());
        PlatformTotpService::confirmEnrollment(
            $admin->fresh(),
            $this->totpCodeForSecret($setup['secret'])
        );

        return ['admin' => $admin->fresh(), 'secret' => $setup['secret']];
    }

    private function createMerchantWithPendingSale(float $net = 90.0, string $orderStatus = 'completed'): array
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $merchant->id,
            'checkout_slug' => 'ant'.substr(md5(uniqid('', true)), 0, 8),
        ]);

        $order = Order::query()->create([
            'tenant_id' => $merchant->id,
            'product_id' => $product->id,
            'status' => $orderStatus,
            'amount' => $net + 10,
            'email' => 'buyer@example.com',
            'payment_method' => 'pix',
        ]);

        TenantWallet::query()->create([
            'tenant_id' => $merchant->id,
            'available_balance' => 0,
            'pending_balance' => $net,
            'currency' => 'BRL',
            'available_pix' => 0,
            'available_card' => 0,
            'available_boleto' => 0,
            'pending_pix' => $net,
            'pending_card' => 0,
            'pending_boleto' => 0,
        ]);

        $tx = WalletTransaction::query()->create([
            'tenant_id' => $merchant->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            'amount_gross' => $net + 10,
            'amount_fee' => 10,
            'amount_net' => $net,
            'meta' => [
                'clears_at' => now()->addDays(2)->toIso8601String(),
                'portion' => 'main',
            ],
        ]);

        return ['merchant' => $merchant->fresh(), 'order' => $order, 'tx' => $tx];
    }

    public function test_admin_can_anticipate_pending_sale_with_pin(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        WithdrawalPolicyService::setManualApprovalPin('1234');
        $ctx = $this->createMerchantWithPendingSale(90.0);
        $admin = $this->platformAdmin();
        $this->travelTo(Carbon::parse('2026-08-28 16:38:00', 'America/Sao_Paulo'));

        $response = $this->actingAs($admin)->post(route('plataforma.usuarios.wallet.anticipate', [
            'user' => $ctx['merchant'],
            'walletTransaction' => $ctx['tx'],
        ]), [
            'manual_approval_pin' => '1234',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $wallet = TenantWallet::query()->where('tenant_id', $ctx['merchant']->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->pending_pix);
        $this->assertEquals(90.0, (float) $wallet->available_pix);
        $this->assertEquals(90.0, (float) $wallet->available_balance);
        $this->assertEquals(0.0, (float) $wallet->pending_balance);

        $pending = $ctx['tx']->fresh();
        $this->assertNotEmpty($pending->meta['released_at'] ?? null);
        $this->assertNotEmpty($pending->meta['anticipated_at'] ?? null);
        $this->assertSame($admin->id, $pending->meta['anticipated_by_user_id'] ?? null);
        $this->assertSame(
            'Saldo Antecipado em '.now()->timezone('America/Sao_Paulo')->format('d/m/Y, H:i'),
            $pending->meta['note'] ?? null
        );

        $sale = WalletTransaction::query()
            ->where('tenant_id', $ctx['merchant']->id)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE)
            ->first();
        $this->assertNotNull($sale);
        $this->assertEquals(90.0, (float) $sale->amount_net);
        $this->assertSame($pending->meta['note'], $sale->meta['note'] ?? null);

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertTrue(
                PlatformAuditLog::query()->where('action', 'platform.wallet.settlement_anticipated')->exists()
            );
        }
    }

    public function test_anticipation_requires_pin_or_totp(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $ctx = $this->createMerchantWithPendingSale();
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post(route('plataforma.usuarios.wallet.anticipate', [
            'user' => $ctx['merchant'],
            'walletTransaction' => $ctx['tx'],
        ]))->assertSessionHasErrors('totp_code');

        $wallet = TenantWallet::query()->where('tenant_id', $ctx['merchant']->id)->first();
        $this->assertEquals(90.0, (float) $wallet->pending_pix);
        $this->assertEquals(0.0, (float) $wallet->available_pix);
    }

    public function test_anticipation_accepts_totp_instead_of_pin(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        WithdrawalPolicyService::setManualApprovalPin('1234');
        $ctx = $this->createMerchantWithPendingSale(40.0);
        $totp = $this->platformAdminWithTotp();

        $this->actingAs($totp['admin'])->post(route('plataforma.usuarios.wallet.anticipate', [
            'user' => $ctx['merchant'],
            'walletTransaction' => $ctx['tx'],
        ]), [
            'totp_code' => $this->totpCodeForSecret($totp['secret']),
        ])->assertRedirect()->assertSessionHas('success');

        $wallet = TenantWallet::query()->where('tenant_id', $ctx['merchant']->id)->first();
        $this->assertEquals(40.0, (float) $wallet->available_pix);
        $this->assertEquals(0.0, (float) $wallet->pending_pix);
    }

    public function test_cannot_anticipate_twice(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        WithdrawalPolicyService::setManualApprovalPin('1234');
        $ctx = $this->createMerchantWithPendingSale(25.0);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post(route('plataforma.usuarios.wallet.anticipate', [
            'user' => $ctx['merchant'],
            'walletTransaction' => $ctx['tx'],
        ]), [
            'manual_approval_pin' => '1234',
        ])->assertSessionHas('success');

        $this->actingAs($admin)->post(route('plataforma.usuarios.wallet.anticipate', [
            'user' => $ctx['merchant'],
            'walletTransaction' => $ctx['tx'],
        ]), [
            'manual_approval_pin' => '1234',
        ])->assertSessionHas('error');

        $this->assertEquals(
            1,
            WalletTransaction::query()
                ->where('tenant_id', $ctx['merchant']->id)
                ->where('type', WalletTransaction::TYPE_CREDIT_SALE)
                ->count()
        );
    }

    public function test_cannot_anticipate_disputed_order(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        WithdrawalPolicyService::setManualApprovalPin('1234');
        $ctx = $this->createMerchantWithPendingSale(50.0, 'disputed');
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post(route('plataforma.usuarios.wallet.anticipate', [
            'user' => $ctx['merchant'],
            'walletTransaction' => $ctx['tx'],
        ]), [
            'manual_approval_pin' => '1234',
        ])->assertSessionHas('error');

        $wallet = TenantWallet::query()->where('tenant_id', $ctx['merchant']->id)->first();
        $this->assertEquals(50.0, (float) $wallet->pending_pix);
        $this->assertEquals(0.0, (float) $wallet->available_pix);
    }

    public function test_cannot_anticipate_another_merchant_transaction(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet_transactions');
        }

        WithdrawalPolicyService::setManualApprovalPin('1234');
        $ctx = $this->createMerchantWithPendingSale();
        $other = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $other->forceFill(['tenant_id' => $other->id])->save();
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post(route('plataforma.usuarios.wallet.anticipate', [
            'user' => $other,
            'walletTransaction' => $ctx['tx'],
        ]), [
            'manual_approval_pin' => '1234',
        ])->assertNotFound();
    }

    public function test_wallet_tab_flags_anticipatable_rows(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet_transactions');
        }

        $ctx = $this->createMerchantWithPendingSale(33.0);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $ctx['merchant'],
                'tab' => 'wallet',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Users/Show')
                ->where('wallet_transactions.data.0.id', $ctx['tx']->id)
                ->where('wallet_transactions.data.0.can_anticipate', true)
                ->where('wallet_transactions.data.0.type_label', 'Venda em liquidação')
            );
    }

    public function test_anticipated_row_shows_as_credited_with_note(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet_transactions');
        }

        WithdrawalPolicyService::setManualApprovalPin('1234');
        $ctx = $this->createMerchantWithPendingSale(12.0);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post(route('plataforma.usuarios.wallet.anticipate', [
            'user' => $ctx['merchant'],
            'walletTransaction' => $ctx['tx'],
        ]), [
            'manual_approval_pin' => '1234',
        ])->assertSessionHas('success');

        $note = $ctx['tx']->fresh()->meta['note'] ?? '';

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $ctx['merchant'],
                'tab' => 'wallet',
                'wallet_sort' => 'id',
                'wallet_direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wallet_transactions.data.0.id', $ctx['tx']->id)
                ->where('wallet_transactions.data.0.can_anticipate', false)
                ->where('wallet_transactions.data.0.type_label', 'Venda creditada')
                ->where('wallet_transactions.data.0.settlement_status', 'available')
                ->where('wallet_transactions.data.0.note', $note)
                ->where('wallet_transactions.data.1.type', WalletTransaction::TYPE_CREDIT_SALE)
                ->where('wallet_transactions.data.1.note', $note)
            );
    }

    public function test_anticipation_note_format(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28 16:38:00', 'America/Sao_Paulo'));
        $this->assertSame(
            'Saldo Antecipado em 28/08/2026, 16:38',
            AdminSettlementAnticipateService::anticipationNote(now())
        );
    }
}
