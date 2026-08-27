<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TenantWallet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlatformUsersIndexListingTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function merchant(string $name, ?Carbon $createdAt = null): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);
        $user->forceFill([
            'tenant_id' => $user->id,
            'created_at' => $createdAt ?? now(),
        ])->save();

        return $user->fresh();
    }

    public function test_default_sort_is_alphabetical_by_name(): void
    {
        $admin = $this->platformAdmin();
        $this->merchant('Zeta Seller');
        $this->merchant('Alpha Seller');
        $this->merchant('Beta Seller');

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Users/Index')
                ->where('sort_by', null)
                ->where('users.data.0.name', 'Alpha Seller')
                ->where('users.data.1.name', 'Beta Seller')
                ->where('users.data.2.name', 'Zeta Seller')
            );
    }

    public function test_sort_by_created_at_asc_and_desc(): void
    {
        $admin = $this->platformAdmin();
        $old = $this->merchant('Old Seller', Carbon::parse('2024-01-01 10:00:00'));
        $new = $this->merchant('New Seller', Carbon::parse('2025-06-01 10:00:00'));

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', [
                'sort_by' => 'created_at',
                'sort_direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sort_by', 'created_at')
                ->where('sort_direction', 'asc')
                ->where('users.data.0.id', $old->id)
                ->where('users.data.1.id', $new->id)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', [
                'sort_by' => 'created_at',
                'sort_direction' => 'desc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sort_by', 'created_at')
                ->where('sort_direction', 'desc')
                ->where('users.data.0.id', $new->id)
                ->where('users.data.1.id', $old->id)
            );
    }

    public function test_sort_by_total_sales(): void
    {
        $admin = $this->platformAdmin();
        $low = $this->merchant('Low Sales');
        $high = $this->merchant('High Sales');

        $productLow = $this->createTestProduct(['tenant_id' => $low->id]);
        $productHigh = $this->createTestProduct(['tenant_id' => $high->id]);

        Order::create([
            'tenant_id' => $low->id,
            'user_id' => $low->id,
            'product_id' => $productLow->id,
            'status' => 'completed',
            'amount' => 10,
            'gateway' => 'stripe',
            'approved_manually' => false,
            'email' => 'a@test.com',
        ]);
        Order::create([
            'tenant_id' => $high->id,
            'user_id' => $high->id,
            'product_id' => $productHigh->id,
            'status' => 'completed',
            'amount' => 500,
            'gateway' => 'stripe',
            'approved_manually' => false,
            'email' => 'b@test.com',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', [
                'sort_by' => 'total_sales',
                'sort_direction' => 'desc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.id', $high->id)
                ->where('users.data.1.id', $low->id)
            );
    }

    public function test_sort_by_balance(): void
    {
        $admin = $this->platformAdmin();
        $poor = $this->merchant('Poor Seller');
        $rich = $this->merchant('Rich Seller');

        TenantWallet::query()->create([
            'tenant_id' => $poor->id,
            'available_balance' => 10,
            'pending_balance' => 0,
            'currency' => 'BRL',
        ]);
        TenantWallet::query()->create([
            'tenant_id' => $rich->id,
            'available_balance' => 999,
            'pending_balance' => 0,
            'currency' => 'BRL',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', [
                'sort_by' => 'balance',
                'sort_direction' => 'desc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.id', $rich->id)
                ->where('users.data.1.id', $poor->id)
            );
    }

    public function test_per_page_allowed_values_and_invalid_fallback(): void
    {
        $admin = $this->platformAdmin();
        foreach (range(1, 30) as $i) {
            $this->merchant(sprintf('Seller %02d', $i));
        }

        foreach ([25, 50, 100] as $perPage) {
            $this->actingAs($admin)
                ->get(route('plataforma.usuarios.index', ['per_page' => $perPage]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('per_page', $perPage)
                    ->where('users.per_page', $perPage)
                );
        }

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', ['per_page' => 7]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('per_page', 25)
                ->where('users.per_page', 25)
                ->has('users.data', 25)
            );
    }

    public function test_invalid_sort_params_are_normalized(): void
    {
        $admin = $this->platformAdmin();
        $this->merchant('Zeta');
        $this->merchant('Alpha');

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', [
                'sort_by' => 'drop_table',
                'sort_direction' => 'sideways',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sort_by', null)
                ->where('sort_direction', null)
                ->where('users.data.0.name', 'Alpha')
                ->where('users.data.1.name', 'Zeta')
            );
    }

    public function test_search_is_preserved_across_pagination(): void
    {
        $admin = $this->platformAdmin();
        foreach (range(1, 30) as $i) {
            $this->merchant(sprintf('Match %02d', $i));
        }
        $this->merchant('Other Seller');

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', [
                'q' => 'Match',
                'per_page' => 25,
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('q', 'Match')
                ->where('users.current_page', 2)
                ->where('users.total', 30)
                ->has('users.data', 5)
            );
    }

    public function test_status_filter_is_preserved_with_sort(): void
    {
        $admin = $this->platformAdmin();
        $pending = $this->merchant('Pending One');
        $pending->forceFill(['account_status' => 'pending'])->save();
        $approved = $this->merchant('Approved One');

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', [
                'status' => 'pending',
                'sort_by' => 'created_at',
                'sort_direction' => 'desc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status', 'pending')
                ->where('sort_by', 'created_at')
                ->has('users.data', 1)
                ->where('users.data.0.id', $pending->id)
                ->where('users.data.0.id', fn ($id) => $id !== $approved->id)
            );
    }

    public function test_guest_and_non_admin_cannot_access(): void
    {
        $this->get(route('plataforma.usuarios.index'))->assertRedirect('/plataforma/login');

        $seller = $this->merchant('Seller Only');
        $this->actingAs($seller)
            ->get(route('plataforma.usuarios.index'))
            ->assertForbidden();
    }

    public function test_empty_listing(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 0)
                ->where('users.total', 0)
            );
    }

    public function test_page_beyond_last_returns_empty_data(): void
    {
        $admin = $this->platformAdmin();
        $this->merchant('Only One');

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', ['page' => 99, 'per_page' => 25]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.current_page', 99)
                ->has('users.data', 0)
                ->where('users.total', 1)
            );
    }

    public function test_created_at_is_present_on_rows(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->merchant('Dated Seller', Carbon::parse('2025-03-15 12:30:00'));

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.id', $seller->id)
                ->where('users.data.0.created_at', fn ($v) => is_string($v) && $v !== '')
            );
    }

    public function test_listing_includes_available_and_pending_balances(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->merchant('Wallet Seller');

        TenantWallet::query()->create([
            'tenant_id' => $seller->id,
            'available_balance' => 120.5,
            'pending_balance' => 45.75,
            'currency' => 'BRL',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.id', $seller->id)
                ->where('users.data.0.saldo_disponivel', fn ($v) => (float) $v === 120.5)
                ->where('users.data.0.saldo_pix', fn ($v) => (float) $v === 45.75)
            );
    }
}
