<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformUserShowProductsWalletTest extends TestCase
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
            'password' => Hash::make('password'),
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function ensureWallet(int $tenantId): void
    {
        if (! Schema::hasTable('tenant_wallets')) {
            return;
        }
        TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => 100,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );
    }

    public function test_admin_can_open_products_tab_scoped_to_merchant(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $other = $this->seller();

        $mine = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Produto Do Seller',
            'checkout_slug' => 'sellerprod01',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);
        $this->createTestProduct([
            'tenant_id' => $other->id,
            'name' => 'Produto Outro',
            'checkout_slug' => 'otherprod01',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', ['user' => $seller, 'tab' => 'products']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Users/Show')
                ->where('tab', 'products')
                ->where('products_total', 1)
                ->has('products.data', 1)
                ->where('products.data.0.id', $mine->id)
                ->where('products.data.0.name', 'Produto Do Seller')
            );
    }

    public function test_seller_cannot_access_merchant_show(): void
    {
        $seller = $this->seller();
        $other = $this->seller();

        $this->actingAs($seller)
            ->get(route('plataforma.usuarios.show', $other))
            ->assertForbidden();
    }

    public function test_non_infoprodutor_returns_forbidden_via_gate(): void
    {
        $admin = $this->platformAdmin();
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', $cliente))
            ->assertForbidden();
    }

    public function test_products_empty_state_and_counters(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', ['user' => $seller, 'tab' => 'products']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products_total', 0)
                ->where('products.total', 0)
                ->where('products_summary.total', 0)
            );
    }

    public function test_products_filters_approval_and_pagination_whitelist(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        for ($i = 1; $i <= 26; $i++) {
            $this->createTestProduct([
                'tenant_id' => $seller->id,
                'name' => "Produto {$i}",
                'checkout_slug' => 'p'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'is_active' => $i % 2 === 0,
                'approval_status' => $i === 1 ? Product::APPROVAL_PENDING : Product::APPROVAL_APPROVED,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'products',
                'products_per_page' => 25,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products_total', 26)
                ->where('products.per_page', 25)
                ->where('products.last_page', 2)
                ->has('products.data', 25)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'products',
                'products_per_page' => 999,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.per_page', 25));

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'products',
                'products_approval' => 'pending',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.total', 1)
                ->where('products.data.0.approval_status', 'pending')
            );

        $unique = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Produto Unico XYZ',
            'checkout_slug' => 'produniquexyz',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'products',
                'products_q' => 'Produto Unico XYZ',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.total', 1)
                ->where('products.data.0.id', $unique->id)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'products',
                'products_q' => (string) $unique->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.total', 1));

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'products',
                'products_sort' => 'DROP TABLE;--',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('products_filters.products_sort', 'created_at'));
    }

    public function test_overview_does_not_load_product_rows(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Hidden Until Tab',
            'checkout_slug' => 'hiddenuntil',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', $seller))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'overview')
                ->where('products_total', 1)
                ->where('products', null)
            );
    }

    public function test_wallet_pagination_scoped_and_independent(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet_transactions');
        }

        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $other = $this->seller();
        $this->ensureWallet($seller->id);
        $this->ensureWallet($other->id);

        for ($i = 0; $i < 30; $i++) {
            WalletTransaction::query()->create([
                'tenant_id' => $seller->id,
                'bucket' => 'pix',
                'type' => WalletTransaction::TYPE_ADMIN_ADJUSTMENT,
                'amount_gross' => 1,
                'amount_fee' => 0,
                'amount_net' => 1,
                'meta' => ['note' => "mov {$i}"],
            ]);
        }
        WalletTransaction::query()->create([
            'tenant_id' => $other->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_ADMIN_ADJUSTMENT,
            'amount_gross' => 99,
            'amount_fee' => 0,
            'amount_net' => 99,
            'meta' => ['note' => 'outro tenant'],
        ]);

        $balanceBefore = (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix');

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'wallet',
                'wallet_per_page' => 25,
                'wallet_page' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'wallet')
                ->where('wallet_transactions.per_page', 25)
                ->where('wallet_transactions.total', 30)
                ->has('wallet_transactions.data', 25)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'wallet',
                'wallet_per_page' => 50,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wallet_transactions.per_page', 50)
                ->has('wallet_transactions.data', 30)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'wallet',
                'wallet_per_page' => 7,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('wallet_transactions.per_page', 25));

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'wallet',
                'wallet_type' => WalletTransaction::TYPE_ADMIN_ADJUSTMENT,
                'products_page' => 9,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'wallet')
                ->where('wallet_transactions.total', 30)
                ->where('products', null)
            );

        $this->assertSame(
            $balanceBefore,
            (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix')
        );
        $this->assertSame(30, WalletTransaction::query()->where('tenant_id', $seller->id)->count());
    }

    public function test_wallet_type_filter_and_empty(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet_transactions');
        }

        $admin = $this->platformAdmin();
        $seller = $this->seller();

        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 10,
            'amount_fee' => 1,
            'amount_net' => 9,
            'meta' => [],
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'wallet',
                'wallet_type' => WalletTransaction::TYPE_MED_HOLD,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('wallet_transactions.total', 0));

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'wallet',
                'wallet_type' => 'not_a_real_type',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wallet_filters.wallet_type', 'all')
                ->where('wallet_transactions.total', 1)
            );
    }

    public function test_wallet_movements_include_settlement_date(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet_transactions');
        }

        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $clearsAt = '2026-09-16T12:00:00+00:00';

        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            'amount_gross' => 100,
            'amount_fee' => 10,
            'amount_net' => 90,
            'meta' => ['clears_at' => $clearsAt],
        ]);
        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 50,
            'amount_fee' => 5,
            'amount_net' => 45,
            'meta' => [
                'clears_at' => '2026-08-01T12:00:00+00:00',
                'released_at' => '2026-08-01T12:00:00+00:00',
            ],
        ]);
        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_ADMIN_ADJUSTMENT,
            'amount_gross' => 20,
            'amount_fee' => 0,
            'amount_net' => 20,
            'meta' => ['note' => 'ajuste'],
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'wallet',
                'wallet_sort' => 'id',
                'wallet_direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'wallet')
                ->where('wallet_transactions.total', 3)
                ->where('wallet_transactions.data.0.type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
                ->where('wallet_transactions.data.0.settlement_status', 'pending')
                ->where('wallet_transactions.data.0.settlement_at', $clearsAt)
                ->where('wallet_transactions.data.0.clears_at', $clearsAt)
                ->where('wallet_transactions.data.1.type', WalletTransaction::TYPE_CREDIT_SALE)
                ->where('wallet_transactions.data.1.settlement_status', 'available')
                ->where('wallet_transactions.data.2.type', WalletTransaction::TYPE_ADMIN_ADJUSTMENT)
                ->where('wallet_transactions.data.2.settlement_status', fn ($v) => $v === null)
                ->where('wallet_transactions.data.2.settlement_at', fn ($v) => $v === null)
            );
    }
}
