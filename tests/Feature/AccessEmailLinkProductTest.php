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

class AccessEmailLinkProductTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
    }

    public function test_link_product_email_includes_external_link_and_platform_login(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'comprador-link@test.com',
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'checkout_slug' => 'produto-link',
            'checkout_config' => [
                'deliverable_link' => 'https://conteudo.externo.test/meu-curso',
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $user->email,
            'is_renewal' => false,
        ]);

        $ok = app(AccessEmailService::class)->sendForOrder($order, true);
        $this->assertTrue($ok->success);

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($user) {
            $this->assertStringContainsString('Acesso externo ao produto', $mail->htmlBody);
            $this->assertStringContainsString('https://conteudo.externo.test/meu-curso', $mail->htmlBody);
            $this->assertStringContainsString('Acesse seus produtos na plataforma', $mail->htmlBody);
            $this->assertStringContainsString('/login', $mail->htmlBody);
            $this->assertStringContainsString('Esqueci minha senha', $mail->htmlBody);
            $this->assertStringContainsString($user->email, $mail->htmlBody);
            $this->assertStringNotContainsString('signature=', $mail->htmlBody);

            return true;
        });

        // Página de obrigado / botão de acesso continua no entregável externo.
        $accessLink = app(AccessEmailService::class)->getAccessLinkForOrder($order->fresh());
        $this->assertSame('https://conteudo.externo.test/meu-curso', $accessLink);
    }
}
