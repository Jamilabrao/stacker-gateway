<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class PlatformAdminUserSearchTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function createInfoprodutor(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ], $overrides));
        $user->forceFill(['tenant_id' => $user->tenant_id ?? $user->id])->save();

        return $user->fresh();
    }

    public function test_users_index_filters_by_name(): void
    {
        $admin = $this->platformAdmin();
        $alice = $this->createInfoprodutor(['name' => 'Alice Infoprod', 'email' => 'alice@example.com']);
        $this->createInfoprodutor(['name' => 'Bob Seller', 'email' => 'bob@example.com']);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', ['q' => 'Alice']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Users/Index')
                ->where('q', 'Alice')
                ->has('users', 1)
                ->where('users.0.id', $alice->id));
    }

    public function test_users_index_filters_by_email_partial(): void
    {
        $admin = $this->platformAdmin();
        $target = $this->createInfoprodutor(['name' => 'Carlos', 'email' => 'carlos.unique@example.com']);
        $this->createInfoprodutor(['name' => 'Diana', 'email' => 'diana@example.com']);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', ['q' => 'unique@example']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users', 1)
                ->where('users.0.id', $target->id));
    }

    public function test_users_index_filters_by_numeric_id(): void
    {
        $admin = $this->platformAdmin();
        $target = $this->createInfoprodutor(['name' => 'Eduardo', 'email' => 'edu@example.com']);
        $this->createInfoprodutor(['name' => 'Fabiana', 'email' => 'fab@example.com']);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', ['q' => (string) $target->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users', 1)
                ->where('users.0.id', $target->id));
    }

    public function test_users_index_filters_by_document(): void
    {
        $admin = $this->platformAdmin();
        $target = $this->createInfoprodutor([
            'name' => 'Gustavo',
            'email' => 'gus@example.com',
            'document' => '12345678901',
        ]);
        $this->createInfoprodutor([
            'name' => 'Helena',
            'email' => 'hel@example.com',
            'document' => '98765432100',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', ['q' => '123456789']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users', 1)
                ->where('users.0.id', $target->id));
    }

    public function test_customers_index_filters_by_document(): void
    {
        $admin = $this->platformAdmin();
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'name' => 'Cliente Doc',
            'email' => 'cliente.doc@example.com',
            'document' => '11122233344',
        ]);

        User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'name' => 'Outro Cliente',
            'email' => 'outro@example.com',
            'document' => '99988877766',
        ]);

        Order::create([
            'tenant_id' => 1,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => $customer->email,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.index', ['q' => '111222333']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Customers/Index')
                ->where('q', '111222333')
                ->has('users.data', 1)
                ->where('users.data.0.id', $customer->id));
    }

    public function test_customers_index_filters_by_numeric_id(): void
    {
        $admin = $this->platformAdmin();
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'name' => 'Cliente ID',
            'email' => 'cliente.id@example.com',
        ]);

        Order::create([
            'tenant_id' => 1,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 30,
            'email' => $customer->email,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.index', ['q' => (string) $customer->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $customer->id));
    }
}
