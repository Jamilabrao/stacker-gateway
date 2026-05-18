<?php

namespace Tests\Feature;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessEmailLoginLinkTest extends TestCase
{
    public function test_member_area_access_email_uses_platform_login_link(): void
    {
        Mail::fake();

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
        $service->sendForOrder($order, true);

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            return str_contains($mail->htmlBody, '/login')
                && ! str_contains($mail->htmlBody, '/access?m=');
        });
    }
}
