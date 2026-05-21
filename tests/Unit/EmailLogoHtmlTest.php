<?php

namespace Tests\Unit;

use App\Mail\PasswordResetMail;
use App\Support\EmailLogoHtml;
use Tests\TestCase;

class EmailLogoHtmlTest extends TestCase
{
    public function test_wrap_uses_opaque_white_background_for_transparent_logos(): void
    {
        $html = EmailLogoHtml::wrap('https://cdn.example.com/logo.png');

        $this->assertStringContainsString('data-email-logo="1"', $html);
        $this->assertStringContainsString('bgcolor="#ffffff"', $html);
        $this->assertStringContainsString('background-color:#ffffff', $html);
        $this->assertStringContainsString('linear-gradient(#ffffff,#ffffff)', $html);
    }

    public function test_password_reset_mail_includes_logo_wrapper(): void
    {
        $mail = new PasswordResetMail('https://app.test/reset', 60, null);
        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('color-scheme" content="light only"', $html);
        $this->assertStringContainsString('background-color:#ffffff', $html);
        $this->assertStringContainsString('Redefinir senha', $html);
    }
}
