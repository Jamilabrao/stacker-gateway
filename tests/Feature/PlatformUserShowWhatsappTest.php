<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class PlatformUserShowWhatsappTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_platform_user_show_includes_whatsapp_from_phone(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'phone' => '5511987654321',
            'name' => 'Seller WA',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $response = $this->actingAs($admin)->get('/plataforma/usuarios/'.$seller->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Platform/Users/Show')
            ->where('merchant.phone', '5511987654321')
            ->where('profile.whatsapp', '(11) 98765-4321')
            ->where('profile.whatsapp_url', 'https://wa.me/5511987654321')
            ->where('profile.phone', '(11) 98765-4321')
        );
    }
}
