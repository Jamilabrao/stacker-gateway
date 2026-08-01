<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Csv;
use App\Support\PlatformCustomerDirectory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformCustomersShowExportTest extends TestCase
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
            'name' => 'Seller Test',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function customerWithCompletedOrder(array $userOverrides = [], array $orderOverrides = []): array
    {
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Produto Demo', 'price' => 100]);

        $customer = User::factory()->create(array_merge([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
            'name' => 'Cliente Completo',
            'email' => 'cliente.completo.test@example.com',
            'phone' => '11999887766',
            'document' => '52998224725',
            'account_status' => 'approved',
            'password' => Hash::make('secret-password'),
            'address_zip' => '01310100',
            'address_street' => 'Av Paulista',
            'address_number' => '1000',
            'address_neighborhood' => 'Bela Vista',
            'address_city' => 'São Paulo',
            'address_state' => 'SP',
        ], $userOverrides));

        $order = Order::create(array_merge([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 150.5,
            'email' => $customer->email,
            'payment_method' => 'pix',
            'gateway' => 'demo',
            'approved_manually' => false,
        ], $orderOverrides));

        return compact('seller', 'product', 'customer', 'order');
    }

    public function test_admin_can_view_customer_details(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'order' => $order] = $this->customerWithCompletedOrder();

        Order::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $customer->id,
            'product_id' => $order->product_id,
            'status' => 'pending',
            'amount' => 40,
            'email' => $customer->email,
            'payment_method' => 'boleto',
            'gateway' => 'demo',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', $customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Customers/Show')
                ->where('customer.id', $customer->id)
                ->where('customer.document', '529.982.247-25')
                ->where('customer.phone', '(11) 99988-7766')
                ->where('summary.approved_count', 1)
                ->where('summary.pending_count', 1)
                ->where('summary.approved_total', 150.5)
                ->where('address.has_address', true)
                ->has('orders.data', 2)
            );

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertDatabaseHas('platform_audit_logs', [
                'action' => 'platform.customer.viewed',
            ]);
        }
    }

    public function test_non_admin_cannot_view_or_export(): void
    {
        $seller = $this->seller();
        ['customer' => $customer] = $this->customerWithCompletedOrder();

        $this->actingAs($seller)
            ->get(route('plataforma.clientes.show', $customer))
            ->assertForbidden();

        $this->actingAs($seller)
            ->get(route('plataforma.clientes.export'))
            ->assertForbidden();
    }

    public function test_missing_customer_returns_404(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', ['user' => 999999]))
            ->assertNotFound();
    }

    public function test_platform_admin_user_is_not_viewable_as_customer(): void
    {
        $admin = $this->platformAdmin();
        $otherAdmin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', $otherAdmin))
            ->assertNotFound();
    }

    public function test_customer_without_completed_orders_is_not_viewable(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
            'email' => 'only.pending@example.com',
        ]);
        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => $customer->email,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', $customer))
            ->assertNotFound();
    }

    public function test_customer_without_cpf_phone_address_shows_safely(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer] = $this->customerWithCompletedOrder([
            'email' => 'cliente.vazio@example.com',
            'phone' => null,
            'document' => null,
            'address_zip' => null,
            'address_street' => null,
            'address_number' => null,
            'address_neighborhood' => null,
            'address_city' => null,
            'address_state' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', $customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customer.phone', null)
                ->where('customer.document', null)
                ->where('address.has_address', false)
            );
    }

    public function test_history_filters_by_status_and_pagination(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'order' => $base] = $this->customerWithCompletedOrder([
            'email' => 'cliente.filtros@example.com',
        ]);

        for ($i = 0; $i < 3; $i++) {
            Order::create([
                'tenant_id' => $base->tenant_id,
                'user_id' => $customer->id,
                'product_id' => $base->product_id,
                'status' => 'pending',
                'amount' => 10 + $i,
                'email' => $customer->email,
                'payment_method' => 'pix',
            ]);
        }

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', [
                'user' => $customer->id,
                'status' => 'pending',
                'per_page' => 25,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'pending')
                ->has('orders.data', 3)
            );
    }

    public function test_index_lists_enriched_columns(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer] = $this->customerWithCompletedOrder([
            'email' => 'cliente.lista@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Customers/Index')
                ->has('users.data', 1)
                ->where('users.data.0.id', $customer->id)
                ->where('users.data.0.total_spent', 150.5)
                ->where('users.data.0.document', '529.982.247-25')
            );
    }

    public function test_export_csv_with_search_and_injection_protection(): void
    {
        $admin = $this->platformAdmin();
        $this->customerWithCompletedOrder([
            'name' => '=CMD()',
            'email' => 'export.safe@example.com',
            'phone' => '+5511999999999',
        ]);
        $this->customerWithCompletedOrder([
            'name' => 'Outro',
            'email' => 'outro.export@example.com',
            'document' => '11144477735',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('plataforma.clientes.export', ['q' => 'export.safe']));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('ID do cliente', $content);
        $this->assertStringContainsString("'=CMD()", $content);
        $this->assertStringContainsString('export.safe@example.com', $content);
        $this->assertStringNotContainsString('outro.export@example.com', $content);
        $this->assertStringNotContainsString('secret-password', $content);
        $this->assertStringNotContainsString('password', strtolower(explode("\n", $content)[0]));

        $delimiterCount = substr_count(explode("\n", $content)[0], ';');
        $this->assertGreaterThan(10, $delimiterCount);

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertTrue(
                PlatformAuditLog::query()->where('action', 'platform.customer.exported')->exists()
            );
        }
    }

    public function test_export_empty_still_returns_headers(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)
            ->get(route('plataforma.clientes.export', ['q' => 'nenhum-resultado-xyz']));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('ID do cliente', $content);
        $lines = array_values(array_filter(explode("\n", trim($content))));
        $this->assertCount(1, $lines);
    }

    public function test_csv_sanitize_helper(): void
    {
        $this->assertSame("'=1+1", Csv::sanitizeCell('=1+1'));
        $this->assertSame("'+123", Csv::sanitizeCell('+123'));
        $this->assertSame("'-10", Csv::sanitizeCell('-10'));
        $this->assertSame("'@x", Csv::sanitizeCell('@x'));
        $this->assertSame('ok', Csv::sanitizeCell('ok'));
        $this->assertSame(PlatformCustomerDirectory::NOT_INFORMED, PlatformCustomerDirectory::NOT_INFORMED);
    }

    public function test_order_items_included_in_history(): void
    {
        if (! Schema::hasTable('order_items')) {
            $this->markTestSkipped('order_items');
        }

        $admin = $this->platformAdmin();
        ['customer' => $customer, 'order' => $order, 'product' => $product, 'seller' => $seller] =
            $this->customerWithCompletedOrder(['email' => 'bump@example.com']);

        $bump = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Bump Extra', 'price' => 20]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'amount' => 100,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bump->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', $customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.has_multiple_items', true)
                ->has('orders.data.0.items', 2)
            );
    }
}
