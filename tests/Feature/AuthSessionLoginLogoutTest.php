<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberAreaDomain;
use App\Models\Product;
use App\Models\User;
use App\Services\Stacker\LicenseService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSessionLoginLogoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_member_login_on_custom_host_redirects_relative_home_not_app_url(): void
    {
        config(['app.url' => 'http://app.example.test']);

        $slug = strtolower('ab'.Str::random(4));
        $product = $this->createTestProduct([
            'name' => 'Área de Membros',
            'slug' => $slug,
            'checkout_slug' => $slug,
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 0,
        ]);

        MemberAreaDomain::create([
            'product_id' => $product->id,
            'type' => MemberAreaDomain::TYPE_CUSTOM,
            'value' => 'curso.test',
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'password' => Hash::make('password'),
        ]);
        $product->users()->attach($user->id);

        $response = $this->post('http://curso.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('app.example.test', $location);
        $path = parse_url($location, PHP_URL_PATH);
        $host = parse_url($location, PHP_URL_HOST);
        $this->assertTrue(
            $location === '/'
            || $path === '/'
            || $path === ''
            || ($host === 'curso.test' && ($path === '/' || $path === null || $path === '')),
            "Expected relative home on custom host, got: {$location}"
        );
        $this->assertAuthenticatedAs($user);
    }

    public function test_member_login_on_path_prefix_redirects_to_member_home_path(): void
    {
        config(['app.url' => 'http://app.example.test']);

        $slug = strtolower('ab'.Str::random(4));
        $product = $this->createTestProduct([
            'name' => 'Área de Membros',
            'slug' => $slug,
            'checkout_slug' => $slug,
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 0,
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'password' => Hash::make('password'),
        ]);
        $product->users()->attach($user->id);

        $response = $this->post("/m/{$slug}/login", [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('app.example.test', $location);
        $this->assertTrue(
            $location === "/m/{$slug}"
            || str_ends_with(rtrim(parse_url($location, PHP_URL_PATH) ?: '', '/'), "/m/{$slug}"),
            "Expected /m/{$slug} home, got: {$location}"
        );
        $this->assertAuthenticatedAs($user);
    }

    public function test_platform_logout_works_when_stacker_license_invalid(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->mock(LicenseService::class, function ($mock) {
            $mock->shouldReceive('isDisabled')->andReturn(false);
            $mock->shouldReceive('isLicenseValid')->andReturn(false);
            $mock->shouldReceive('isBlocked')->andReturn(true);
        });

        $this->actingAs($admin)
            ->get('/plataforma/dashboard')
            ->assertNotFound();

        $response = $this->actingAs($admin)->post('/plataforma/logout');

        $response->assertRedirect('/plataforma/login');
        $this->assertGuest();
    }
}
