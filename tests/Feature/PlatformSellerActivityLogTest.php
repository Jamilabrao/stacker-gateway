<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\SellerActivityLog;
use App\Models\User;
use App\Services\SellerActivityLogService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlatformSellerActivityLogTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function merchant(string $name = 'Seller'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);
        $user->forceFill([
            'tenant_id' => $user->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $user->fresh();
    }

    public function test_platform_admin_can_view_seller_activity_logs(): void
    {
        $admin = $this->platformAdmin();
        $merchant = $this->merchant('Alpha Seller');

        SellerActivityLogService::record(
            actor: $merchant,
            action: SellerActivityLogService::WITHDRAWAL_REQUESTED,
            metadata: ['amount' => 150.5, 'bucket' => 'pix'],
        );

        $this->actingAs($admin)
            ->get(route('plataforma.seller-activity-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/SellerActivityLogs/Index')
                ->where('logs.data.0.merchant.name', 'Alpha Seller')
                ->where('logs.data.0.action', SellerActivityLogService::WITHDRAWAL_REQUESTED)
                ->where('logs.data.0.source', 'panel')
            );
    }

    public function test_seller_cannot_view_admin_activity_logs(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant)
            ->get(route('plataforma.seller-activity-logs.index'))
            ->assertForbidden();
    }

    public function test_filters_by_merchant_action_and_date(): void
    {
        $admin = $this->platformAdmin();
        $alpha = $this->merchant('Alpha Seller');
        $beta = $this->merchant('Beta Seller');

        Carbon::setTestNow('2026-08-10 12:00:00');
        SellerActivityLogService::record(
            actor: $alpha,
            action: SellerActivityLogService::PAYOUT_SETTINGS_UPDATED,
            metadata: ['pix_key_type' => 'email', 'pix_key_masked' => '****.com'],
        );

        Carbon::setTestNow('2026-08-16 12:00:00');
        SellerActivityLogService::record(
            actor: $beta,
            action: SellerActivityLogService::WITHDRAWAL_REQUESTED,
            metadata: ['amount' => 10],
        );
        SellerActivityLogService::record(
            actor: $alpha,
            action: SellerActivityLogService::TEAM_MEMBER_CREATED,
            metadata: ['email' => 'membro@test.com'],
        );
        Carbon::setTestNow();

        $this->actingAs($admin)
            ->get(route('plataforma.seller-activity-logs.index', [
                'merchant_id' => $alpha->id,
                'group' => SellerActivityLogService::GROUP_TEAM,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.action', SellerActivityLogService::TEAM_MEMBER_CREATED)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.seller-activity-logs.index', [
                'action' => SellerActivityLogService::PAYOUT_SETTINGS_UPDATED,
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-12',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.action', SellerActivityLogService::PAYOUT_SETTINGS_UPDATED)
                ->where('logs.data.0.merchant.name', 'Alpha Seller')
            );
    }

    public function test_creating_team_role_writes_admin_seller_activity_log(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $owner = $this->merchant();
        $this->actingAs($owner)
            ->post('/usuarios/equipe/cargos', [
                'name' => 'Suporte',
                'permissions' => ['vendas.view' => true],
            ])
            ->assertRedirect('/usuarios/equipe');

        $this->assertDatabaseHas('seller_activity_logs', [
            'tenant_id' => $owner->id,
            'actor_user_id' => $owner->id,
            'action' => SellerActivityLogService::TEAM_ROLE_CREATED,
            'action_group' => SellerActivityLogService::GROUP_TEAM,
        ]);
        $this->assertSame(1, SellerActivityLog::query()->count());
    }

    public function test_mask_value_never_stores_full_secret(): void
    {
        $this->assertSame('****', SellerActivityLogService::maskValue('ab'));
        $this->assertSame('****cdef', SellerActivityLogService::maskValue('1234cdef'));
    }

    public function test_creating_coupon_writes_admin_seller_activity_log(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $owner = $this->merchant();
        $this->actingAs($owner)
            ->post(route('cupons.store'), [
                'code' => 'PROMO10',
                'type' => 'percent',
                'value' => 10,
                'is_active' => true,
            ])
            ->assertRedirect(route('cupons.index'));

        $this->assertDatabaseHas('seller_activity_logs', [
            'tenant_id' => $owner->id,
            'actor_user_id' => $owner->id,
            'action' => SellerActivityLogService::COUPON_CREATED,
            'action_group' => SellerActivityLogService::GROUP_COMMERCE,
        ]);
    }

    public function test_admin_page_exposes_expanded_group_options(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('plataforma.seller-activity-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/SellerActivityLogs/Index')
                ->has('group_options')
                ->where('group_options', function ($options) {
                    $values = collect($options)->pluck('value')->all();

                    return empty(array_diff([
                        SellerActivityLogService::GROUP_AUTH,
                        SellerActivityLogService::GROUP_KYC,
                        SellerActivityLogService::GROUP_PRODUCT,
                        SellerActivityLogService::GROUP_COMMERCE,
                        SellerActivityLogService::GROUP_PARTNER,
                        SellerActivityLogService::GROUP_INTEGRATION,
                        SellerActivityLogService::GROUP_DISPUTE,
                        SellerActivityLogService::GROUP_SUBSCRIPTION,
                    ], $values));
                })
            );
    }
}
