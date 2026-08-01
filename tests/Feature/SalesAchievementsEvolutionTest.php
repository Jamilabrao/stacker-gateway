<?php

namespace Tests\Feature;

use App\Enums\SalesAchievementRewardStatus;
use App\Jobs\NotifySalesAchievementEarnedJob;
use App\Models\Order;
use App\Models\SalesAchievement;
use App\Models\SalesAchievementUnlock;
use App\Models\User;
use App\Services\SalesAchievementGrantService;
use App\Services\SalesAchievementRewardStatusService;
use App\Services\SalesAchievementsService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesAchievementsEvolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class]);
        if (! Schema::hasTable('sales_achievement_unlocks')) {
            $this->markTestSkipped('Migração de unlocks não aplicada.');
        }
    }

    private function admin(): User
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
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    public function test_admin_can_create_achievement_with_reward(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('plataforma.conquistas.store'), [
                'slug' => 'meta-teste-reward',
                'name' => 'Meta Teste',
                'description' => 'Desc',
                'metric_type' => 'revenue',
                'threshold' => 10000,
                'sort_order' => 1,
                'is_active' => true,
                'reward_name' => 'iPhone',
                'reward_description' => 'Prêmio teste',
                'reward_internal_notes' => 'Só admin',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $row = SalesAchievement::query()->where('slug', 'meta-teste-reward')->first();
        $this->assertNotNull($row);
        $this->assertSame('iPhone', $row->reward_name);
        $this->assertSame('Só admin', $row->reward_internal_notes);
    }

    public function test_progress_uses_absolute_percent_against_next_target(): void
    {
        SalesAchievement::query()->delete();
        SalesAchievement::query()->create([
            'slug' => 'm10',
            'name' => '10k',
            'metric_type' => 'revenue',
            'threshold' => 10000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        SalesAchievement::query()->create([
            'slug' => 'm50',
            'name' => '50k',
            'metric_type' => 'revenue',
            'threshold' => 50000,
            'sort_order' => 2,
            'is_active' => true,
            'reward_name' => 'Notebook',
        ]);

        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'achprog01',
        ]);
        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 32000,
            'payment_method' => 'pix',
            'gateway' => 'efi',
        ]);
        $this->assertNotNull($order);

        $progress = app(SalesAchievementsService::class)->getProgressForTenant($seller->id);
        $this->assertSame(32000.0, $progress['current_value']);
        $this->assertSame('50k', $progress['next_achievement']['name']);
        $this->assertEqualsWithDelta(64.0, $progress['progress_percent'], 0.1);
        $this->assertEqualsWithDelta(18000.0, $progress['remaining'], 0.1);
        $this->assertSame('Notebook', $progress['next_achievement']['reward_name']);
    }

    public function test_grant_is_idempotent_and_snapshots_reward(): void
    {
        Bus::fake([NotifySalesAchievementEarnedJob::class]);
        SalesAchievement::query()->delete();
        $achievement = SalesAchievement::query()->create([
            'slug' => 'grant-10k',
            'name' => 'Grant 10k',
            'metric_type' => 'revenue',
            'threshold' => 10000,
            'sort_order' => 1,
            'is_active' => true,
            'reward_name' => 'iPhone 17',
            'reward_description' => 'Desc prêmio',
        ]);

        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'achgrant01',
        ]);
        Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 15000,
            'payment_method' => 'pix',
            'gateway' => 'efi',
        ]);

        $grant = app(SalesAchievementGrantService::class);
        $first = $grant->syncTenant($seller->id);
        $second = $grant->syncTenant($seller->id);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertSame(1, SalesAchievementUnlock::query()->where('tenant_id', $seller->id)->count());

        $unlock = SalesAchievementUnlock::query()->first();
        $this->assertSame('iPhone 17', $unlock->reward_name_snapshot);
        $this->assertSame(SalesAchievementRewardStatus::Pending->value, $unlock->reward_status);
        $this->assertEquals(10000.0, (float) $unlock->threshold_snapshot);

        $achievement->update(['reward_name' => 'Outro prêmio', 'threshold' => 99999]);
        $unlock->refresh();
        $this->assertSame('iPhone 17', $unlock->reward_name_snapshot);
        $this->assertEquals(10000.0, (float) $unlock->threshold_snapshot);

        Bus::assertDispatched(NotifySalesAchievementEarnedJob::class);
    }

    public function test_manual_and_pending_orders_do_not_count(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'achmetric01',
        ]);
        Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50000,
            'payment_method' => 'pix',
            'gateway' => 'efi',
        ]);
        Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50000,
            'payment_method' => 'pix',
            'gateway' => 'manual',
        ]);
        Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 1000,
            'payment_method' => 'pix',
            'gateway' => 'efi',
            'approved_manually' => true,
        ]);

        $total = app(SalesAchievementsService::class)->getValidSalesTotal($seller->id);
        $this->assertSame(0.0, $total);
    }

    public function test_reward_status_transitions_and_history(): void
    {
        $admin = $this->admin();
        $seller = $this->seller();
        $achievement = SalesAchievement::query()->create([
            'slug' => 'status-10k',
            'name' => 'Status 10k',
            'metric_type' => 'revenue',
            'threshold' => 10,
            'sort_order' => 1,
            'is_active' => true,
            'reward_name' => 'Gift',
        ]);
        $unlock = SalesAchievementUnlock::query()->create([
            'tenant_id' => $seller->id,
            'sales_achievement_id' => $achievement->id,
            'unlocked_at' => now(),
            'metric_value_at_unlock' => 10,
            'metric_type' => 'revenue',
            'threshold_snapshot' => 10,
            'name_snapshot' => 'Status 10k',
            'reward_name_snapshot' => 'Gift',
            'reward_status' => SalesAchievementRewardStatus::Pending->value,
        ]);

        $service = app(SalesAchievementRewardStatusService::class);
        $service->updateStatus($unlock, 'in_production', $admin, ['note' => 'Produzindo']);
        $this->assertSame('in_production', $unlock->fresh()->reward_status);

        $service->updateStatus($unlock->fresh(), 'sent', $admin, [
            'reward_carrier' => 'Correios',
            'reward_tracking_code' => 'BR123',
            'note' => 'Enviado',
        ]);
        $fresh = $unlock->fresh();
        $this->assertSame('sent', $fresh->reward_status);
        $this->assertSame('BR123', $fresh->reward_tracking_code);
        $this->assertSame(2, $fresh->statusHistory()->count());

        $this->expectException(\InvalidArgumentException::class);
        $service->updateStatus($fresh, 'pending', $admin, ['note' => 'voltar']);
    }

    public function test_seller_conquistas_page_hides_internal_notes(): void
    {
        SalesAchievement::query()->delete();
        $seller = $this->seller();
        SalesAchievement::query()->create([
            'slug' => 'seller-view',
            'name' => 'Seller View',
            'metric_type' => 'revenue',
            'threshold' => 999999999,
            'sort_order' => 1,
            'is_active' => true,
            'reward_name' => 'Prêmio',
            'reward_internal_notes' => 'SEGREDO ADMIN',
        ]);

        $this->actingAs($seller)
            ->get(route('conquistas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Conquistas/Index')
                ->where('progress.next_achievement.reward_name', 'Prêmio')
                ->missing('progress.next_achievement.reward_internal_notes')
            );
    }

    public function test_ranking_tab_requires_admin(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller)
            ->get('/plataforma/conquistas?tab=relatorio')
            ->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)
            ->get('/plataforma/conquistas?tab=relatorio')
            ->assertOk();
    }

    public function test_all_completed_when_above_last_threshold(): void
    {
        SalesAchievement::query()->delete();
        SalesAchievement::query()->create([
            'slug' => 'only',
            'name' => 'Única',
            'metric_type' => 'revenue',
            'threshold' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'achall01',
        ]);
        Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 5000,
            'payment_method' => 'pix',
            'gateway' => 'efi',
        ]);

        $progress = app(SalesAchievementsService::class)->getProgressForTenant($seller->id);
        $this->assertTrue($progress['all_completed']);
        $this->assertNull($progress['next_achievement']);
        $this->assertSame(100.0, $progress['progress_percent']);
    }
}
