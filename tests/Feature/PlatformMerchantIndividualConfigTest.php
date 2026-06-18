<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\ApiApplication;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApiPixAccess;
use App\Services\MinimumChargeService;
use App\Support\ApiScopes;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformMerchantIndividualConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    /**
     * @return array{app: ApiApplication, public: string, secret: string}
     */
    private function createApiApp(int $tenantId): array
    {
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();

        $app = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'API App',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'Individual Config API'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy-unused'),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
            'webhook_url' => null,
            'webhook_secret' => null,
        ]);

        return ['app' => $app, 'public' => $public, 'secret' => $secret];
    }

    public function test_admin_can_disable_api_pix_for_merchant(): void
    {
        Setting::set('api_pix_enabled', '1', null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchant->forceFill([
            'tenant_id' => $merchant->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $merchant), [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'api_pix_mode' => 'disabled',
        ])->assertRedirect();

        $this->assertFalse(ApiPixAccess::effectiveForTenant((int) $merchant->id));
        $this->assertSame(ApiPixAccess::MODE_DISABLED, ApiPixAccess::tenantMode((int) $merchant->id));
    }

    public function test_admin_can_set_tenant_api_pix_minimum_and_enforce_on_api(): void
    {
        Setting::set('api_pix_enabled', '1', null);
        Setting::set(MinimumChargeService::SETTING_API_PIX, '0.01', null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchantA = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchantA->forceFill([
            'tenant_id' => $merchantA->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $merchantB = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchantB->forceFill([
            'tenant_id' => $merchantB->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $merchantA), [
            'name' => $merchantA->name,
            'email' => $merchantA->email,
            'api_pix_minimum_charge_brl' => 10,
            'use_platform_api_pix_minimum' => false,
        ])->assertRedirect();

        $service = app(MinimumChargeService::class);
        $this->assertSame(10.0, $service->apiPixMinimumBrlForTenant((int) $merchantA->id));
        $this->assertSame(0.01, $service->apiPixMinimumBrlForTenant((int) $merchantB->id));

        $keysA = $this->createApiApp((int) $merchantA->id);

        $this->withHeaders([
            'X-Public-Key' => $keysA['public'],
            'X-Secret-Key' => $keysA['secret'],
        ])->postJson('/api/v1/payments/pix', [
            'customer' => ['email' => 'a@exemplo.com', 'name' => 'A'],
            'amount' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $rules = app(MinimumChargeService::class)->apiPixMinimumBrlForTenant((int) $merchantB->id);
        $this->assertSame(0.01, $rules);
        $this->assertGreaterThanOrEqual($rules, 0.05);
    }

    public function test_clearing_tenant_charge_limit_overrides_inherits_global(): void
    {
        Setting::set(MinimumChargeService::SETTING_API_PIX, '0.01', null);
        Setting::set(MinimumChargeService::SETTING_PLATFORM, '0', null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();
        $tenantId = (int) $merchant->id;

        $service = app(MinimumChargeService::class);
        $service->setTenantApiPixOverride($tenantId, 10.0);
        $service->setTenantPlatformOverride($tenantId, 5.0);

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $merchant), [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'use_platform_api_pix_minimum' => true,
            'use_platform_platform_minimum' => true,
        ])->assertRedirect();

        $this->assertNull($service->tenantApiPixOverride($tenantId));
        $this->assertNull($service->tenantPlatformOverride($tenantId));
        $this->assertSame(0.01, $service->apiPixMinimumBrlForTenant($tenantId));
        $this->assertSame(0.0, $service->platformMinimumBrlForTenant($tenantId));
    }

    public function test_users_index_exposes_edit_user_id_from_query(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index', ['edit' => $merchant->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Users/Index')
                ->where('edit_user_id', $merchant->id)
            );
    }

    public function test_users_index_includes_api_pix_and_charge_limits_per_merchant(): void
    {
        Setting::set('api_pix_enabled', '1', null);
        Setting::set(MinimumChargeService::SETTING_API_PIX, '0.01', null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();
        ApiPixAccess::setTenantMode((int) $merchant->id, ApiPixAccess::MODE_DISABLED);
        app(MinimumChargeService::class)->setTenantApiPixOverride((int) $merchant->id, 7.5);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Users/Index')
                ->has('platform_charge_limits')
                ->where('users', fn ($users) => collect($users)->contains(fn ($u) => $u['id'] === $merchant->id
                    && $u['api_pix_mode'] === ApiPixAccess::MODE_DISABLED
                    && $u['api_pix_enabled_effective'] === false
                    && ($u['charge_limits']['api_pix_minimum_charge_brl'] ?? null) === 7.5
                ))
            );
    }
}
