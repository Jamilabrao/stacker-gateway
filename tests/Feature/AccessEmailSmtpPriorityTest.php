<?php

namespace Tests\Feature;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AccessEmailSendResult;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessEmailSmtpPriorityTest extends TestCase
{
    public function test_uses_platform_global_smtp_when_tenant_has_none(): void
    {
        Mail::fake();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'global-user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);

        $buyer = User::factory()->create(['tenant_id' => $seller->id, 'email' => 'buyer-smtp@test.com']);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-smtp',
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer-smtp@test.com',
        ]);

        $result = app(\App\Services\AccessEmailService::class)->sendForOrder($order, true);

        $this->assertTrue($result->success);
        Mail::assertSent(AccessGrantedMail::class);
    }

    public function test_returns_smtp_not_configured_when_no_provider(): void
    {
        Mail::fake();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_LINK,
            'checkout_config' => ['deliverable_link' => 'https://example.com/file'],
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@test.com',
        ]);

        $result = app(\App\Services\AccessEmailService::class)->sendForOrder($order, true);

        $this->assertFalse($result->success);
        $this->assertSame(AccessEmailSendResult::REASON_SMTP_NOT_CONFIGURED, $result->reason);
        Mail::assertNothingSent();
    }
}
