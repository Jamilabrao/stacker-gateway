<?php

namespace Tests\Feature\Utmify;

use App\Events\PixGenerated;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Models\UtmifyOrderDispatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UtmifyCheckoutOrderRegressionTest extends TestCase
{
    public function test_pix_generated_includes_session_utms_when_order_metadata_has_token(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1, 'checkout_slug' => 'regpix1']);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy regression',
            'api_key' => 'regression-key',
            'is_active' => true,
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'regression-session-token',
            'step' => CheckoutSession::STEP_FORM_STARTED,
            'utm_source' => 'facebook',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'launch',
            'utm_content' => 'ad-1',
            'utm_term' => 'buy-now',
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'email' => 'regression@example.com',
            'gateway' => 'pix',
            'metadata' => [
                'checkout_session_token' => $session->session_token,
                'utm_source' => 'facebook',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'launch',
                'utm_content' => 'ad-1',
                'utm_term' => 'buy-now',
            ],
        ]);

        $session->update(['order_id' => $order->id, 'step' => CheckoutSession::STEP_CONVERTED]);

        event(new PixGenerated($order, ['qr_code' => 'test']));

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.utmify.com.br/api-credentials/orders') {
                return false;
            }
            $body = $request->data();
            $tp = $body['trackingParameters'] ?? [];

            foreach (\App\Models\CheckoutSession::TRACKING_FIELD_KEYS as $key) {
                if (! array_key_exists($key, $tp)) {
                    return false;
                }
            }

            return ($body['status'] ?? '') === 'waiting_payment'
                && ($tp['utm_source'] ?? '') === 'facebook'
                && ($tp['utm_campaign'] ?? '') === 'launch'
                && ($tp['utm_content'] ?? '') === 'ad-1'
                && $tp['sck'] === null
                && $tp['src'] === null;
        });

        $order->refresh();
        $this->assertNotEmpty($order->metadata['utmify_waiting_sent_at'] ?? null);

        $this->assertTrue(
            UtmifyOrderDispatch::query()
                ->where('order_id', $order->id)
                ->where('utmify_status', 'waiting_payment')
                ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT)
                ->exists()
        );
    }
}
