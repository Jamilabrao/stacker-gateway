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
        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($seller) {
            $fromAddress = $mail->from[0]['address'] ?? $mail->from[0]->address ?? null;
            $replyAddress = null;
            $reply = $mail->replyTo[0] ?? null;
            if (is_array($reply)) {
                $replyAddress = $reply['address'] ?? null;
            } elseif (is_object($reply)) {
                $replyAddress = $reply->address ?? null;
            }

            $this->assertSame($seller->email, $fromAddress, 'From should be seller email, got: '.json_encode($mail->from));
            $this->assertSame($seller->email, $replyAddress, 'Reply-To should be seller email, got: '.json_encode($mail->replyTo));

            return true;
        });
    }

    public function test_uses_checkout_support_email_as_from_when_set(): void
    {
        Mail::fake();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'seller-login@test.com',
            'name' => 'Seller Nome',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'global-user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
        Setting::set('mail_from_address', 'plataforma@getfy.test', null);
        Setting::set('mail_from_name', 'Plataforma Global', null);

        $buyer = User::factory()->create(['tenant_id' => $seller->id, 'email' => 'buyer-support-from@test.com']);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-sup',
            'checkout_config' => [
                'footer' => ['support_email' => 'suporte-seller@loja.test'],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer-support-from@test.com',
        ]);

        $result = app(\App\Services\AccessEmailService::class)->sendForOrder($order, true);

        $this->assertTrue($result->success);
        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            $fromAddress = $mail->from[0]['address'] ?? $mail->from[0]->address ?? null;
            $this->assertSame('suporte-seller@loja.test', $fromAddress, 'From should be support email, got: '.json_encode($mail->from));
            $this->assertNotSame('plataforma@getfy.test', $fromAddress);

            return true;
        });
    }

    public function test_returns_smtp_not_configured_when_no_provider(): void
    {
        Mail::fake();

        foreach ([
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
            'email_provider', 'mail_from_address', 'mail_from_name',
            'hostinger_smtp_username', 'hostinger_mail_from_address',
            'sendgrid_api_key', 'sendgrid_mail_from_address',
        ] as $key) {
            Setting::set($key, '', null);
        }

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
