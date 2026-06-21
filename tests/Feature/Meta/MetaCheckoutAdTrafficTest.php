<?php

namespace Tests\Feature\Meta;

use App\Jobs\Meta\SendMetaTrackingEventJob;
use App\Models\CheckoutSession;
use App\Models\MetaTrackingEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaCheckoutAdTrafficTest extends TestCase
{
    public function test_checkout_get_with_fbclid_stores_meta_attribution_on_session(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $this->createTestProduct([
            'name' => 'Produto meta ads',
            'checkout_slug' => 'metaad1',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '123456789', 'access_token' => 'capitok'],
                    ],
                ],
            ],
        ]);

        $response = $this->get('/c/metaad1?utm_source=facebook&utm_campaign=test&fbclid=CLICK_ID_XYZ');
        $response->assertOk();

        $session = CheckoutSession::query()
            ->where('checkout_slug', 'metaad1')
            ->where('utm_source', 'facebook')
            ->where('utm_campaign', 'test')
            ->latest('id')
            ->first();

        $this->assertNotNull($session);
        $this->assertSame('CLICK_ID_XYZ', $session->meta_fbclid);
        $this->assertNotEmpty($session->meta_fbc);
        $this->assertStringContainsString('CLICK_ID_XYZ', $session->meta_fbc);
    }

    public function test_checkout_get_with_ad_params_queues_pageview_and_initiate_checkout(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $this->createTestProduct([
            'checkout_slug' => 'metaad2',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '987654321', 'access_token' => 'server_tok'],
                    ],
                ],
            ],
        ]);

        $this->get('/c/metaad2?utm_source=ig&fbclid=ad_click_99')->assertOk();

        $session = CheckoutSession::query()->where('checkout_slug', 'metaad2')->latest('id')->first();
        $this->assertNotNull($session);

        $this->assertDatabaseHas('meta_tracking_events', [
            'event_name' => 'PageView',
            'event_id' => 'pv:'.$session->session_token,
            'pixel_id' => '987654321',
        ]);
        $this->assertDatabaseHas('meta_tracking_events', [
            'event_name' => 'InitiateCheckout',
            'event_id' => 'chk:'.$session->session_token,
            'pixel_id' => '987654321',
        ]);

        Queue::assertPushed(SendMetaTrackingEventJob::class, 2);
    }

    public function test_checkout_get_without_ad_params_does_not_queue_landing_backup(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $this->createTestProduct([
            'checkout_slug' => 'metaad3',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '111222333', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $this->get('/c/metaad3')->assertOk();

        $this->assertSame(0, MetaTrackingEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_meta_event_context_resolver_uses_meta_fbclid_when_fbc_missing(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'metaad4']);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'metaad4',
            'session_token' => 'sess-fbclid-fallback',
            'step' => CheckoutSession::STEP_VISIT,
            'meta_fbclid' => 'fallback_click_id',
            'meta_fbc' => null,
        ]);

        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forCheckoutSession($session);

        $this->assertNotNull($context->fbc);
        $this->assertStringContainsString('fallback_click_id', $context->fbc);
    }
}
