<?php

namespace Tests\Feature;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccessEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessEmailLoginLinkTest extends TestCase
{
    public function test_member_area_access_email_uses_signed_magic_link(): void
    {
        Mail::fake();
        $this->seedPlatformSmtp();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $buyer = User::factory()->create(['tenant_id' => 1, 'email' => 'buyer@test.com']);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-teste',
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@test.com',
            'metadata' => ['access_password_temp' => encrypt('senha123')],
        ]);

        $service = app(AccessEmailService::class);
        $result = $service->sendForOrder($order, true);

        $this->assertTrue($result->success);

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            return str_contains($mail->htmlBody, '/access')
                && str_contains($mail->htmlBody, 'signature=')
                && ! str_contains($mail->htmlBody, 'href="http://localhost/login"');
        });

        $link = $service->getAccessLinkForOrder($order->fresh());
        $this->assertStringContainsString('/access', $link);
        $this->assertStringContainsString('signature=', $link);
        $this->assertStringNotContainsString('/login', parse_url($link, PHP_URL_PATH) ?: '');
    }

    private function seedPlatformSmtp(): void
    {
        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
    }
}
