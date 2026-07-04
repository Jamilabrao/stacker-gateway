<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Jobs\IntegraxSendSmsJob;
use App\Models\ApiApplication;
use App\Models\IntegraxSmsDispatch;
use App\Models\Order;
use App\Models\PlatformIntegraxSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegraxOrderEventsTest extends TestCase
{
    public function test_order_completed_queues_order_paid_sms(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'api_token' => 'order-token',
            'sender_from' => '29094',
            'event_order_paid_enabled' => true,
            'message_order_paid' => '{nome}, pago {valor}',
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
        ]);

        event(new OrderCompleted($order));

        Queue::assertPushed(IntegraxSendSmsJob::class, function (IntegraxSendSmsJob $job) use ($order) {
            $dispatch = IntegraxSmsDispatch::query()->find($job->dispatchId);

            return $dispatch
                && $dispatch->event_type === PlatformIntegraxSetting::EVENT_ORDER_PAID
                && $dispatch->order_id === $order->id;
        });
    }

    public function test_order_completed_queues_access_granted_for_access_product(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'api_token' => 'order-token',
            'sender_from' => '29094',
            'event_access_granted_enabled' => true,
            'message_access_granted' => '{nome}, acesso: {link_acesso}',
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $buyer = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'type' => Product::TYPE_LINK,
            'checkout_config' => ['deliverable_link' => 'https://acesso.example.com/login'],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
        ]);

        event(new OrderCompleted($order));

        Queue::assertPushed(IntegraxSendSmsJob::class, function (IntegraxSendSmsJob $job) use ($order) {
            $dispatch = IntegraxSmsDispatch::query()->find($job->dispatchId);

            return $dispatch
                && $dispatch->event_type === PlatformIntegraxSetting::EVENT_ACCESS_GRANTED
                && $dispatch->order_id === $order->id;
        });
    }

    public function test_pix_generated_queues_pix_sms_when_enabled(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'api_token' => 'pix-token',
            'sender_from' => '29094',
            'event_pix_generated_enabled' => true,
            'message_pix_generated' => 'PIX {valor} gerado',
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 30,
            'email' => 'pix@example.com',
            'phone' => '11966554433',
        ]);

        event(new PixGenerated($order, ['copy_paste' => 'pix-code']));

        Queue::assertPushed(IntegraxSendSmsJob::class, function (IntegraxSendSmsJob $job) use ($order) {
            $dispatch = IntegraxSmsDispatch::query()->find($job->dispatchId);

            return $dispatch
                && $dispatch->event_type === PlatformIntegraxSetting::EVENT_PIX_GENERATED
                && $dispatch->order_id === $order->id;
        });
    }

    public function test_duplicate_order_event_is_not_queued_twice(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'api_token' => 'order-token',
            'sender_from' => '29094',
            'event_order_paid_enabled' => true,
            'message_order_paid' => '{nome}, pago {valor}',
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
        ]);

        IntegraxSmsDispatch::query()->create([
            'tenant_id' => 1,
            'order_id' => $order->id,
            'event_type' => PlatformIntegraxSetting::EVENT_ORDER_PAID,
            'phone' => '5511977665544',
            'message' => 'Já enviado',
            'status' => IntegraxSmsDispatch::STATUS_PENDING,
        ]);

        event(new OrderCompleted($order));

        Queue::assertNothingPushed();
    }

    public function test_order_completed_does_not_queue_sms_for_api_pix_order(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'sms_checkout_only' => true,
            'api_token' => 'order-token',
            'sender_from' => '29094',
            'event_order_paid_enabled' => true,
            'message_order_paid' => '{nome}, pago {valor}',
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $apiApp = ApiApplication::create([
            'tenant_id' => 1,
            'name' => 'API PIX',
            'slug' => 'api-pix-test',
            'api_key_hash' => ApiApplication::hashApiKey('test-key'),
            'public_key' => ApiApplication::generatePublicKey(),
            'secret_key_hash' => ApiApplication::hashSecretKey(ApiApplication::generateSecretKey()),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => \App\Support\ApiScopes::legacyDefaults(),
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'api_application_id' => $apiApp->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
        ]);

        event(new OrderCompleted($order));

        Queue::assertNothingPushed();
    }

    public function test_api_pix_order_can_receive_sms_when_checkout_only_disabled(): void
    {
        Queue::fake();

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'sms_checkout_only' => false,
            'api_token' => 'order-token',
            'sender_from' => '29094',
            'event_order_paid_enabled' => true,
            'message_order_paid' => '{nome}, pago {valor}',
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $apiApp = ApiApplication::create([
            'tenant_id' => 1,
            'name' => 'API PIX',
            'slug' => 'api-pix-test-2',
            'api_key_hash' => ApiApplication::hashApiKey('test-key-2'),
            'public_key' => ApiApplication::generatePublicKey(),
            'secret_key_hash' => ApiApplication::hashSecretKey(ApiApplication::generateSecretKey()),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => \App\Support\ApiScopes::legacyDefaults(),
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'api_application_id' => $apiApp->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
        ]);

        event(new OrderCompleted($order));

        Queue::assertPushed(IntegraxSendSmsJob::class);
    }

    public function test_send_job_marks_dispatch_as_sent(): void
    {
        Http::fake([
            'sms.aresfun.com/*' => Http::response(['ok' => true], 200),
        ]);

        PlatformIntegraxSetting::instance()->update([
            'is_active' => true,
            'api_token' => 'job-token',
            'sender_from' => '29094',
        ]);

        $dispatch = IntegraxSmsDispatch::query()->create([
            'tenant_id' => 1,
            'order_id' => null,
            'checkout_session_id' => null,
            'event_type' => PlatformIntegraxSetting::EVENT_ORDER_PAID,
            'phone' => '5511998877665',
            'message' => 'Teste envio job',
            'status' => IntegraxSmsDispatch::STATUS_PENDING,
        ]);

        $job = new IntegraxSendSmsJob($dispatch->id);
        $job->handle(app(\App\Services\Integrax\IntegraxService::class));

        $dispatch->refresh();
        $this->assertSame(IntegraxSmsDispatch::STATUS_SENT, $dispatch->status);
        $this->assertNotNull($dispatch->sent_at);
    }
}
