<?php

namespace Tests\Feature;

use App\Jobs\IntegraxSendSmsJob;
use App\Models\CheckoutSession;
use App\Models\IntegraxSmsDispatch;
use App\Models\PlatformIntegraxSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegraxCartRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-03 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_queues_first_cart_recovery_step_for_eligible_session(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'api_token' => 'recovery-token',
            'sender_from' => '29094',
            'event_cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Primeira {nome}! {link}'],
                ['delay_minutes' => 1440, 'message' => 'Segunda {nome}! {link}'],
            ],
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'checkout_slug' => 'cart-recovery-1',
        ]);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'integrax-recovery-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead SMS',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('integrax:process-cart-recovery')->assertSuccessful();

        Queue::assertPushed(IntegraxSendSmsJob::class);

        $dispatch = IntegraxSmsDispatch::query()->first();
        $this->assertSame(0, $dispatch->sequence_step);
        $this->assertStringContainsString('Primeira Lead SMS', $dispatch->message);
    }

    public function test_command_queues_second_step_when_first_already_sent(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'api_token' => 'recovery-token',
            'sender_from' => '29094',
            'event_cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Primeira {nome}!'],
                ['delay_minutes' => 30, 'message' => 'Segunda {nome}!'],
            ],
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'cart-recovery-2']);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'integrax-recovery-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead2@example.com',
            'name' => 'Lead 2',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(60),
            'form_filled_at' => now()->subMinutes(45),
        ]);

        IntegraxSmsDispatch::query()->create([
            'tenant_id' => 1,
            'checkout_session_id' => $session->id,
            'event_type' => PlatformIntegraxSetting::EVENT_CART_RECOVERY,
            'sequence_step' => 0,
            'phone' => '5511988776655',
            'message' => 'Primeira Lead 2!',
            'status' => IntegraxSmsDispatch::STATUS_SENT,
            'sent_at' => now()->subMinutes(20),
        ]);

        $this->artisan('integrax:process-cart-recovery')->assertSuccessful();

        Queue::assertPushed(IntegraxSendSmsJob::class);

        $latest = IntegraxSmsDispatch::query()->orderByDesc('id')->first();
        $this->assertSame(1, $latest->sequence_step);
        $this->assertSame('Segunda Lead 2!', $latest->message);
    }

    public function test_command_skips_when_integrax_inactive(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => false,
            'event_cart_recovery_enabled' => true,
        ]);

        $this->artisan('integrax:process-cart-recovery')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
