<?php

namespace Tests\Unit;

use App\Support\BrandingAssetUrls;
use Illuminate\Http\Request;
use Tests\TestCase;

class BrandingAssetUrlsTest extends TestCase
{
    public function test_resolves_localhost_http_storage_url_to_current_https_host(): void
    {
        $request = Request::create(
            'https://meu-dominio.test/plataforma/configuracoes',
            'GET',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $this->app->instance('request', $request);

        $resolved = BrandingAssetUrls::resolve(
            'http://localhost:8085/storage/white-label/global/logo.png'
        );

        $this->assertSame(
            'https://meu-dominio.test/storage/white-label/global/logo.png',
            $resolved
        );
    }

    public function test_resolves_relative_storage_path_to_absolute_url(): void
    {
        $request = Request::create(
            'https://meu-dominio.test/login',
            'GET',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $this->app->instance('request', $request);

        $resolved = BrandingAssetUrls::resolve('white-label/global/logo.png');

        $this->assertSame(
            'https://meu-dominio.test/storage/white-label/global/logo.png',
            $resolved
        );
    }
}
