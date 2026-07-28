<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberAreaResolver;
use App\Support\PublicAppUrl;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PublicUrlSettingsTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);

        $this->tmpBase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'getfy-public-url-feat-'.uniqid('', true);
        File::makeDirectory($this->tmpBase.'/.docker', 0777, true);
        File::makeDirectory($this->tmpBase.'/bootstrap/cache', 0777, true);
        file_put_contents($this->tmpBase.'/.env', "APP_URL=https://valuxpay.com\n");

        // O serviço padrão usa base_path(); nos testes de feature validamos runtime + endpoint
        // sem sobrescrever o .env real do projeto — o apply no controller usa base_path().
        // Por isso este feature test foca em config runtime via service binding.
        $this->app->instance(
            \App\Services\InstallationPublicUrlService::class,
            new \App\Services\InstallationPublicUrlService($this->tmpBase)
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpBase) && is_dir($this->tmpBase)) {
            File::deleteDirectory($this->tmpBase);
        }
        parent::tearDown();
    }

    public function test_platform_admin_can_load_public_url_data(): void
    {
        config([
            'app.url' => 'https://app.valuxpay.com',
            'getfy.webhook_public_url' => 'https://app.valuxpay.com',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->getJson('/plataforma/configuracoes/url-publica/data')
            ->assertOk()
            ->assertJsonPath('resolved_public_url', 'https://app.valuxpay.com')
            ->assertJsonPath('urls_diverged', false);
    }

    public function test_platform_admin_can_update_public_url_and_member_links_follow(): void
    {
        config([
            'app.url' => 'https://valuxpay.com',
            'getfy.webhook_public_url' => 'https://valuxpay.com',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->putJson('/plataforma/configuracoes/url-publica', [
            'url' => 'https://app.valuxpay.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('url', 'https://app.valuxpay.com');

        $this->assertSame('https://app.valuxpay.com', PublicAppUrl::base());
        $this->assertSame('https://app.valuxpay.com', config('app.url'));
        $this->assertSame('https://app.valuxpay.com', config('getfy.webhook_public_url'));

        $env = (string) file_get_contents($this->tmpBase.'/.env');
        $this->assertStringContainsString('APP_URL=https://app.valuxpay.com', $env);
        $this->assertStringContainsString('GETFY_WEBHOOK_PUBLIC_URL=https://app.valuxpay.com', $env);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => '2xyzv09',
        ]);

        $this->assertSame(
            'https://app.valuxpay.com/m/2xyzv09',
            app(MemberAreaResolver::class)->baseUrlForProduct($product)
        );
    }

    public function test_settings_page_includes_public_url_props(): void
    {
        config([
            'app.url' => 'https://app.valuxpay.com',
            'getfy.webhook_public_url' => 'https://app.valuxpay.com',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/configuracoes?tab=url_publica')
            ->assertOk()
            ->assertInertia(fn ($assert) => $assert
                ->component('Settings/Index')
                ->where('resolved_public_url', 'https://app.valuxpay.com')
                ->has('public_url_meta'));
    }

    public function test_invalid_url_returns_422(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson('/plataforma/configuracoes/url-publica', ['url' => 'ftp://bad.example.com'])
            ->assertStatus(422);
    }
}
