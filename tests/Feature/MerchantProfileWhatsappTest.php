<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MerchantProfileSnapshot;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MerchantProfileWhatsappTest extends TestCase
{
    public function test_whatsapp_uses_user_phone_not_pix_key(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'phone'));

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'phone' => '11988776655',
            'payout_settings' => [
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key' => 'pix@example.com',
            ],
        ]);

        $profile = MerchantProfileSnapshot::forUser($user);

        $this->assertSame('(11) 98877-6655', $profile['whatsapp']);
        $this->assertSame('https://wa.me/5511988776655', $profile['whatsapp_url']);
        $this->assertSame('(11) 98877-6655', $profile['phone']);
    }

    public function test_whatsapp_does_not_use_pix_phone_key(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'phone' => null,
            'payout_settings' => [
                'cajupay_pix_key_type' => 'phone',
                'cajupay_pix_key' => '11977665544',
            ],
        ]);

        $profile = MerchantProfileSnapshot::forUser($user);

        $this->assertNull($profile['whatsapp']);
        $this->assertNull($profile['whatsapp_url']);
        $this->assertNull($profile['phone']);
        $this->assertSame('11977665544', $profile['payout_pix_key']);
        $this->assertSame('phone', $profile['payout_pix_key_type']);
    }
}
