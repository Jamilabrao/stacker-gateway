<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformIntegraxSetting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class IntegraxSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_platform_admin_can_view_integrax_page(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.integrax.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/Integrax/Index'));
    }

    public function test_platform_admin_can_save_integrax_settings_with_recovery_steps(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put(route('plataforma.integrax.update'), [
                'is_active' => true,
                'api_token' => 'test-token-integrax',
                'sender_from' => '29094',
                'event_cart_recovery_enabled' => true,
                'event_order_paid_enabled' => true,
                'event_access_granted_enabled' => false,
                'event_pix_generated_enabled' => false,
                'message_order_paid' => '{nome}, pago {valor}',
                'message_access_granted' => '{nome}, acesso: {link_acesso}',
                'message_pix_generated' => 'PIX {valor}',
                'cart_recovery_steps' => [
                    ['delay_value' => 10, 'delay_unit' => 'minutes', 'message' => 'Primeira {nome}! {link}'],
                    ['delay_value' => 24, 'delay_unit' => 'hours', 'message' => 'Segunda {nome}! {link}'],
                ],
            ])
            ->assertRedirect(route('plataforma.integrax.index'));

        $settings = PlatformIntegraxSetting::instance();
        $this->assertTrue($settings->is_active);
        $this->assertSame('29094', $settings->sender_from);
        $this->assertTrue($settings->event_cart_recovery_enabled);
        $this->assertTrue($settings->isConfigured());
        $this->assertCount(2, $settings->cartRecoverySteps());
        $this->assertSame(10, $settings->cartRecoverySteps()[0]['delay_minutes']);
        $this->assertSame(1440, $settings->cartRecoverySteps()[1]['delay_minutes']);
    }

    public function test_accepts_recovery_step_delay_above_twelve_hours_in_minutes(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put(route('plataforma.integrax.update'), [
                'is_active' => true,
                'api_token' => 'test-token-integrax',
                'sender_from' => '29094',
                'event_cart_recovery_enabled' => true,
                'cart_recovery_steps' => [
                    ['delay_value' => 10, 'delay_unit' => 'minutes', 'message' => 'Primeira'],
                    ['delay_value' => 1450, 'delay_unit' => 'minutes', 'message' => 'Segunda'],
                ],
            ])
            ->assertRedirect(route('plataforma.integrax.index'));

        $settings = PlatformIntegraxSetting::instance();
        $this->assertSame(1450, $settings->cartRecoverySteps()[1]['delay_minutes']);
    }

    public function test_rejects_message_over_160_chars(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put(route('plataforma.integrax.update'), [
                'is_active' => true,
                'api_token' => 'token',
                'sender_from' => '29094',
                'message_order_paid' => str_repeat('x', 161),
                'cart_recovery_steps' => [
                    ['delay_value' => 10, 'delay_unit' => 'minutes', 'message' => 'Ok'],
                ],
            ])
            ->assertSessionHasErrors('message_order_paid');
    }

    public function test_rejects_non_increasing_recovery_steps(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put(route('plataforma.integrax.update'), [
                'is_active' => true,
                'api_token' => 'token',
                'sender_from' => '29094',
                'event_cart_recovery_enabled' => true,
                'cart_recovery_steps' => [
                    ['delay_value' => 24, 'delay_unit' => 'hours', 'message' => 'Primeira'],
                    ['delay_value' => 10, 'delay_unit' => 'minutes', 'message' => 'Segunda'],
                ],
            ])
            ->assertSessionHasErrors('cart_recovery_steps');
    }
}
