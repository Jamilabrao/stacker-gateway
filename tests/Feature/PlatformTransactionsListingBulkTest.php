<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\PlatformTransactionsListing;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformTransactionsListingBulkTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(string $name = 'Seller Alpha', string $email = 'seller.alpha@example.com'): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => $name,
            'email' => $email,
            'account_status' => 'approved',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function makeOrder(User $seller, array $overrides = []): Order
    {
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => $overrides['product_name'] ?? 'Produto TX',
        ]);
        unset($overrides['product_name']);

        $customer = null;
        if (! array_key_exists('user_id', $overrides)) {
            $customer = User::factory()->create([
                'role' => User::ROLE_CLIENTE,
                'tenant_id' => null,
                'name' => 'Cliente TX',
                'email' => 'cliente.tx.'.uniqid().'@example.com',
            ]);
            $overrides['user_id'] = $customer->id;
            $overrides['email'] = $overrides['email'] ?? $customer->email;
        }

        return Order::create(array_merge([
            'tenant_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'gateway' => 'demo',
            'payment_method' => 'pix',
            'approved_manually' => false,
        ], $overrides));
    }

    public function test_search_finds_order_by_infoprodutor_beyond_first_page(): void
    {
        $admin = $this->platformAdmin();
        $sellerTarget = $this->seller('Vendedor Unico XYZ', 'vendedor.unico@example.com');
        $sellerOther = $this->seller('Outro Seller', 'outro.seller@example.com');

        for ($i = 0; $i < 30; $i++) {
            $this->makeOrder($sellerOther, [
                'status' => 'completed',
                'amount' => 10 + $i,
                'email' => "other{$i}@example.com",
            ]);
        }

        $target = $this->makeOrder($sellerTarget, [
            'status' => 'completed',
            'amount' => 99,
            'email' => 'alvo@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', [
                'q' => 'Vendedor Unico XYZ',
                'per_page' => 25,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Transactions/Index')
                ->where('filters.per_page', 25)
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $target->id)
            );
    }

    public function test_per_page_whitelist_and_invalid_fallback(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        for ($i = 0; $i < 30; $i++) {
            $this->makeOrder($seller, ['status' => 'pending', 'email' => "p{$i}@example.com"]);
        }

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', ['per_page' => 50]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.per_page', 50)
                ->where('orders.per_page', 50)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', ['per_page' => 999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.per_page', PlatformTransactionsListing::DEFAULT_PER_PAGE)
            );
    }

    public function test_status_filter_combined_with_search(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller('Seller Filter', 'seller.filter@example.com');
        $pending = $this->makeOrder($seller, [
            'status' => 'pending',
            'email' => 'pendente.filter@example.com',
        ]);
        $this->makeOrder($seller, [
            'status' => 'completed',
            'email' => 'pago.filter@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', [
                'status' => 'pending',
                'q' => 'filter@example.com',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $pending->id)
            );
    }

    public function test_bulk_delete_pending_all_or_nothing_success(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $a = $this->makeOrder($seller, ['status' => 'pending', 'email' => 'a@example.com']);
        $b = $this->makeOrder($seller, ['status' => 'pending', 'email' => 'b@example.com']);

        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => [$a->id, $b->id],
                'reason' => 'Testes gerados incorretamente',
            ])
            ->assertRedirect(route('plataforma.transacoes.index'));

        $this->assertDatabaseMissing('orders', ['id' => $a->id]);
        $this->assertDatabaseMissing('orders', ['id' => $b->id]);

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertTrue(
                PlatformAuditLog::query()->where('action', 'platform.order.bulk_deleted')->exists()
            );
        }
    }

    public function test_bulk_delete_rolls_back_when_any_not_pending(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $pending = $this->makeOrder($seller, ['status' => 'pending', 'email' => 'ok@example.com']);
        $paid = $this->makeOrder($seller, ['status' => 'completed', 'email' => 'paid@example.com']);

        $this->actingAs($admin)
            ->from(route('plataforma.transacoes.index'))
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => [$pending->id, $paid->id],
            ])
            ->assertRedirect(route('plataforma.transacoes.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('orders', ['id' => $pending->id, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['id' => $paid->id, 'status' => 'completed']);
    }

    public function test_bulk_delete_rejects_non_admin_and_over_limit(): void
    {
        $seller = $this->seller();
        $order = $this->makeOrder($seller, ['status' => 'pending']);

        $this->actingAs($seller)
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => [$order->id],
            ])
            ->assertForbidden();

        $admin = $this->platformAdmin();
        $ids = range(1, 101);
        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => $ids,
            ])
            ->assertSessionHasErrors('ids');
    }

    public function test_bulk_delete_blocks_when_status_changed_before_delete(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $order = $this->makeOrder($seller, ['status' => 'pending', 'email' => 'race@example.com']);

        // Simula mudança de status antes da exclusão (já não pendente).
        $order->forceFill(['status' => 'completed'])->save();

        $this->actingAs($admin)
            ->from(route('plataforma.transacoes.index'))
            ->post(route('plataforma.transacoes.pedidos.bulk-destroy'), [
                'ids' => [$order->id],
            ])
            ->assertRedirect(route('plataforma.transacoes.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed']);
    }
}
