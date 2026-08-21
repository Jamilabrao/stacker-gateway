<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformDashboardAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_period_does_not_divide_by_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Dashboard')
                ->where('kpis.vendas_totais', 0)
                ->where('kpis.quantidade_vendas', 0)
                ->where('kpis.ticket_medio', 0)
                ->where('growth.taxa_aprovacao', 0)
                ->where('funnel.tentativas', 0)
                ->where('comparisons.vendas_totais.delta_percent', 0)
            );
    }

    public function test_volume_ticket_and_funnel_use_real_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $admin = $this->platformAdmin();
        $seller = $this->infoprodutor();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $this->createOrder($seller, $buyer, $product, ['status' => 'completed', 'amount' => 100, 'payment_method' => 'pix', 'gateway' => 'efi']);
        $this->createOrder($seller, $buyer, $product, ['status' => 'completed', 'amount' => 50, 'payment_method' => 'card', 'gateway' => 'efi']);
        $this->createOrder($seller, $buyer, $product, ['status' => 'rejected', 'amount' => 80, 'payment_method' => 'card', 'gateway' => 'efi']);
        $this->createOrder($seller, $buyer, $product, ['status' => 'pending', 'amount' => 40, 'payment_method' => 'pix', 'gateway' => 'efi']);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.vendas_totais', 150)
                ->where('kpis.quantidade_vendas', 2)
                ->where('kpis.ticket_medio', 75)
                ->where('funnel.tentativas', 4)
                ->where('growth.taxa_aprovacao', 66.7)
                ->where('growth.infoprodutores_com_vendas', 1)
                ->has('payment_methods')
                ->has('acquirers')
                ->has('top_sellers', 1)
                ->has('top_products', 1)
            );
    }

    public function test_comparison_uses_previous_equivalent_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $admin = $this->platformAdmin();
        $seller = $this->infoprodutor();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $today = $this->createOrder($seller, $buyer, $product, ['status' => 'completed', 'amount' => 200]);
        $yesterday = $this->createOrder($seller, $buyer, $product, ['status' => 'completed', 'amount' => 100]);
        Order::query()->whereKey($today->id)->update([
            'created_at' => Carbon::parse('2026-08-21 08:00:00'),
            'updated_at' => Carbon::parse('2026-08-21 08:00:00'),
        ]);
        Order::query()->whereKey($yesterday->id)->update([
            'created_at' => Carbon::parse('2026-08-20 10:00:00'),
            'updated_at' => Carbon::parse('2026-08-20 10:00:00'),
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.vendas_totais', 200)
                ->where('comparisons.vendas_totais.previous', 100)
                ->where('comparisons.vendas_totais.delta_percent', 100)
            );
    }

    public function test_new_buyers_count_first_completed_purchase_in_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $admin = $this->platformAdmin();
        $seller = $this->infoprodutor();
        $newBuyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $returning = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $this->createOrder($seller, $newBuyer, $product, ['status' => 'completed', 'amount' => 40]);

        $old = $this->createOrder($seller, $returning, $product, ['status' => 'completed', 'amount' => 40]);
        Order::query()->whereKey($old->id)->update([
            'created_at' => Carbon::parse('2026-07-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-07-01 10:00:00'),
        ]);
        $this->createOrder($seller, $returning, $product, ['status' => 'completed', 'amount' => 60]);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('growth.novos_compradores', 1)
                ->where('growth.compradores_recorrentes', 1)
            );
    }

    public function test_total_omits_comparison(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=total')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('comparisons', null)
                ->where('grafico.compare', false)
            );
    }

    public function test_chart_hoje_has_24_hourly_points(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('grafico.points', 24)
                ->where('grafico.granularity', 'hour')
                ->has('grafico_vendas', 24)
            );
    }

    public function test_seller_cannot_access_platform_dashboard(): void
    {
        $seller = $this->infoprodutor();

        $this->actingAs($seller)
            ->get('/plataforma/dashboard')
            ->assertForbidden();
    }

    public function test_faturamento_still_uses_official_kpi(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $admin = $this->platformAdmin();
        $seller = $this->infoprodutor();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $order = $this->createOrder($seller, $buyer, $product, [
            'status' => 'completed',
            'amount' => 100,
            'payment_method' => 'pix',
            'gateway' => 'efi',
        ]);

        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100,
            'amount_fee' => 12.50,
            'amount_net' => 87.5,
            'meta' => [],
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.faturamento_taxas_cobradas', 12.5));
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function infoprodutor(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $attrs = ['tenant_id' => $seller->id, 'account_status' => 'approved'];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_APPROVED;
        }
        $seller->forceFill($attrs)->save();

        return $seller;
    }

    private function createOrder(User $seller, User $buyer, $product, array $overrides): Order
    {
        return Order::create(array_merge([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'efi',
            'metadata' => [],
        ], $overrides));
    }
}
