<?php

namespace Tests\Unit;

use App\Services\InstallationPublicUrlService;
use App\Services\MemberAreaResolver;
use App\Support\PublicAppUrl;
use App\Models\Product;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class InstallationPublicUrlServiceTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'getfy-public-url-'.uniqid('', true);
        File::makeDirectory($this->tmpBase.'/bootstrap/cache', 0777, true);
        File::makeDirectory($this->tmpBase.'/.docker', 0777, true);
        file_put_contents($this->tmpBase.'/.env', "APP_URL=https://valuxpay.com\nGETFY_WEBHOOK_PUBLIC_URL=https://valuxpay.com\n");
        file_put_contents($this->tmpBase.'/.docker/stack.env', "GETFY_APP_URL=https://valuxpay.com\nGETFY_WEBHOOK_PUBLIC_URL=https://valuxpay.com\n");
        file_put_contents($this->tmpBase.'/.docker/Caddyfile.domains', "valuxpay.com {\n\ttls internal\n\treverse_proxy app:80\n}\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpBase)) {
            File::deleteDirectory($this->tmpBase);
        }
        parent::tearDown();
    }

    public function test_normalize_accepts_host_without_scheme(): void
    {
        $service = new InstallationPublicUrlService($this->tmpBase);

        $this->assertSame('https://app.valuxpay.com', $service->normalize('app.valuxpay.com'));
        $this->assertSame('https://app.valuxpay.com', $service->normalize('https://app.valuxpay.com/'));
        $this->assertSame('https://app.valuxpay.com', $service->normalize('https://app.valuxpay.com/path?x=1'));
    }

    public function test_normalize_rejects_empty_and_bad_scheme(): void
    {
        $service = new InstallationPublicUrlService($this->tmpBase);

        $this->expectException(InvalidArgumentException::class);
        $service->normalize('ftp://app.valuxpay.com');
    }

    public function test_apply_syncs_env_docker_and_runtime_config(): void
    {
        config([
            'app.url' => 'https://valuxpay.com',
            'getfy.webhook_public_url' => 'https://valuxpay.com',
        ]);

        $service = new InstallationPublicUrlService($this->tmpBase);
        $result = $service->apply('https://app.valuxpay.com');

        $this->assertSame('https://app.valuxpay.com', $result['url']);
        $this->assertSame('app.valuxpay.com', $result['host']);
        $this->assertSame('https://app.valuxpay.com', config('app.url'));
        $this->assertSame('https://app.valuxpay.com', config('getfy.webhook_public_url'));
        $this->assertSame('https://app.valuxpay.com', PublicAppUrl::base());

        $env = (string) file_get_contents($this->tmpBase.'/.env');
        $this->assertStringContainsString('APP_URL=https://app.valuxpay.com', $env);
        $this->assertStringContainsString('GETFY_WEBHOOK_PUBLIC_URL=https://app.valuxpay.com', $env);
        $this->assertStringContainsString('GETFY_APP_URL=https://app.valuxpay.com', $env);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $env);

        $this->assertSame('https://app.valuxpay.com', trim((string) file_get_contents($this->tmpBase.'/.docker/app.url')));

        $stack = (string) file_get_contents($this->tmpBase.'/.docker/stack.env');
        $this->assertStringContainsString('GETFY_APP_URL=https://app.valuxpay.com', $stack);
        $this->assertStringContainsString('GETFY_WEBHOOK_PUBLIC_URL=https://app.valuxpay.com', $stack);

        $caddy = (string) file_get_contents($this->tmpBase.'/.docker/Caddyfile.domains');
        $this->assertStringContainsString('app.valuxpay.com {', $caddy);
    }

    public function test_member_area_path_link_uses_synced_public_url(): void
    {
        config([
            'app.url' => 'https://valuxpay.com',
            'getfy.webhook_public_url' => 'https://valuxpay.com',
        ]);

        $service = new InstallationPublicUrlService($this->tmpBase);
        $service->apply('https://app.valuxpay.com');

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => '2xyzv09',
        ]);

        $url = app(MemberAreaResolver::class)->baseUrlForProduct($product);

        $this->assertSame('https://app.valuxpay.com/m/2xyzv09', $url);
    }

    public function test_snapshot_detects_diverged_urls(): void
    {
        config([
            'app.url' => 'https://app.valuxpay.com',
            'getfy.webhook_public_url' => 'https://valuxpay.com',
        ]);

        $service = new InstallationPublicUrlService($this->tmpBase);
        $snap = $service->snapshot();

        $this->assertTrue($snap['urls_diverged']);
        $this->assertSame('https://valuxpay.com', $snap['resolved_public_url']);
    }
}
