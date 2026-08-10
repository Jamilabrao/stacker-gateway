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

class AccessEmailPasswordBlockTest extends TestCase
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

    public function test_appends_password_block_when_template_has_no_senha_placeholder(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'aluno-access@test.com',
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-teste-email',
            'checkout_config' => [
                'email_template' => [
                    'body_html' => '<p>Olá {nome_cliente}. Link: <a href="{link_acesso}">Acessar</a></p>',
                ],
            ],
        ]);

        $plain = 'SenhaSegura123xyz';
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $user->email,
            'metadata' => ['access_password_temp' => encrypt($plain)],
            'is_renewal' => false,
        ]);

        $order->load(['product', 'user']);
        $ok = app(AccessEmailService::class)->sendForOrder($order, true);
        $this->assertTrue($ok->success);

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($plain) {
            return str_contains($mail->htmlBody, $plain)
                && str_contains($mail->htmlBody, 'Guarde seus dados de acesso');
        });
    }

    public function test_does_not_duplicate_password_when_template_includes_senha_placeholder(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'aluno-access2@test.com',
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-teste-email-2',
            'checkout_config' => [
                'email_template' => [
                    'body_html' => '<p>{nome_cliente} — senha: <code>{senha}</code> — <a href="{link_acesso}">ok</a></p>',
                ],
            ],
        ]);

        $plain = 'UniquePass456';
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $user->email,
            'metadata' => ['access_password_temp' => encrypt($plain)],
            'is_renewal' => false,
        ]);

        $order->load(['product', 'user']);
        app(AccessEmailService::class)->sendForOrder($order, true);

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($plain) {
            $this->assertSame(1, substr_count($mail->htmlBody, $plain));
            $this->assertStringNotContainsString('Guarde seus dados de acesso', $mail->htmlBody);

            return true;
        });
    }

    public function test_appends_forgot_password_block_when_password_missing(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'aluno-sem-senha@test.com',
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-sem-senha',
            'checkout_config' => [
                'email_template' => [
                    'body_html' => '<p>Olá {nome_cliente}. Link: <a href="{link_acesso}">Fazer login</a></p>',
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $user->email,
            'metadata' => [],
            'is_renewal' => false,
        ]);

        $order->load(['product', 'user']);
        $ok = app(AccessEmailService::class)->sendForOrder($order, true);
        $this->assertTrue($ok->success);

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            return str_contains($mail->htmlBody, 'href="http://localhost/login"')
                && str_contains($mail->htmlBody, 'Crie sua senha de acesso')
                && str_contains($mail->htmlBody, 'Esqueci minha senha')
                && str_contains($mail->htmlBody, 'href="http://localhost/esqueci-senha"');
        });
    }
}
