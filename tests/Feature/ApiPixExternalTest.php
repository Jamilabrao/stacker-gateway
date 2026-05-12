<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApiPixAccess;
use App\Services\EffectiveMerchantFees;
use Tests\TestCase;

class ApiPixExternalTest extends TestCase
{
    /**
     * @return array{app: ApiApplication, public: string, secret: string, legacy: string}
     */
    private function createApiApp(int $tenantId, bool $active = true): array
    {
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();
        $legacy = 'getfy_test_legacy_key';

        $app = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'API App',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'API App'),
            'api_key_hash' => ApiApplication::hashApiKey($legacy),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => $active,
            'webhook_url' => null,
            'default_return_url' => null,
            'webhook_secret' => null,
            'checkout_sidebar_bg' => null,
        ]);

        return ['app' => $app, 'public' => $public, 'secret' => $secret, 'legacy' => $legacy];
    }

    public function test_api_auth_accepts_public_and_secret_headers(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $app = $this->createApiApp((int) $seller->id);

        $resp = $this->withHeaders([
            'X-Public-Key' => $app['public'],
            'X-Secret-Key' => $app['secret'],
        ])->get('/api/v1/payments/not-found-order');

        $resp->assertStatus(404);
    }

    public function test_api_auth_fallback_legacy_bearer_kept(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $app = $this->createApiApp((int) $seller->id);

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer '.$app['legacy'],
        ])->get('/api/v1/payments/not-found-order');

        $resp->assertStatus(404);
    }

    public function test_api_pix_toggle_global_and_tenant_override(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set('api_pix_enabled', false, null);
        $this->assertFalse(ApiPixAccess::effectiveForTenant((int) $seller->id));

        Setting::set('api_pix_enabled', true, (int) $seller->id);
        $this->assertTrue(ApiPixAccess::effectiveForTenant((int) $seller->id));
    }

    public function test_api_pix_fee_uses_dedicated_rule_for_api_source(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'merchant_fees' => [
                'pix' => ['percent' => 1.0, 'fixed' => 0.50],
                'api_pix' => ['percent' => 5.0, 'fixed' => 1.00],
            ],
        ])->save();

        $apiCalc = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 100.00, 'api');
        $checkoutCalc = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 100.00, 'checkout');

        $this->assertSame(6.00, $apiCalc['fee']);
        $this->assertSame(1.50, $checkoutCalc['fee']);
    }

    public function test_api_checkout_pro_uses_api_pix_fee_bucket(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'merchant_fees' => [
                'pix' => ['percent' => 1.0, 'fixed' => 0.0],
                'api_pix' => ['percent' => 4.0, 'fixed' => 2.0],
            ],
        ])->save();

        $hosted = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 100.00, 'api_checkout_pro');
        $this->assertSame(6.00, $hosted['fee']);
    }

    public function test_card_via_api_source_still_uses_checkout_card_fee(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'merchant_fees' => [
                'pix' => ['percent' => 1.0, 'fixed' => 0.0],
                'api_pix' => ['percent' => 9.0, 'fixed' => 0.0],
                'card' => ['percent' => 3.0, 'fixed' => 1.0],
            ],
        ])->save();

        $apiCard = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'card', 100.00, 'api');
        $this->assertSame(4.00, $apiCard['fee']);
    }

}
