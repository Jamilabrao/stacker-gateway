<?php

namespace Tests\Unit;

use App\Services\GamificationService;
use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

class PublicImageUrlResolutionTest extends TestCase
{
    public function test_safe_url_resolves_localhost_storage_to_current_https_host(): void
    {
        $request = Request::create(
            'https://meu-dominio.test/painel',
            'GET',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $this->app->instance('request', $request);

        $resolved = SafeUrl::normalizeAppImageUrl(
            'http://localhost:8085/storage/platform/support-icon.png'
        );

        $this->assertSame(
            'https://meu-dominio.test/storage/platform/support-icon.png',
            $resolved
        );
    }

    public function test_safe_url_resolves_relative_storage_path(): void
    {
        $request = Request::create(
            'https://meu-dominio.test/painel',
            'GET',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $this->app->instance('request', $request);

        $resolved = SafeUrl::normalizeAppImageUrl('white-label/global/logo.png');

        $this->assertSame(
            'https://meu-dominio.test/storage/white-label/global/logo.png',
            $resolved
        );
    }
}
