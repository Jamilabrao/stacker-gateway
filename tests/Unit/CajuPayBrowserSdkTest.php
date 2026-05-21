<?php

namespace Tests\Unit;

use App\Support\CajuPayBrowserSdk;
use Illuminate\Http\Request;
use Tests\TestCase;

class CajuPayBrowserSdkTest extends TestCase
{
    public function test_http_request_uses_same_origin_proxy_base(): void
    {
        $request = Request::create('http://getfy-gateway.test/checkout', 'GET');
        $base = CajuPayBrowserSdk::apiBaseUrlForBrowser($request);

        $this->assertStringEndsWith('/checkout/cajupay/sdk-api', $base);
        $this->assertStringStartsWith('http://getfy-gateway.test', $base);
    }

    public function test_https_request_uses_direct_api_base(): void
    {
        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);

        $request = Request::create('https://loja.example.com/checkout', 'GET');
        $base = CajuPayBrowserSdk::apiBaseUrlForBrowser($request);

        $this->assertSame('https://api.cajupay.com.br', $base);
    }

    public function test_proxy_disabled_on_http_returns_direct_api(): void
    {
        config(['services.cajupay.sdk_browser_proxy' => false]);

        $request = Request::create('http://localhost/checkout', 'GET');
        $base = CajuPayBrowserSdk::apiBaseUrlForBrowser($request);

        $this->assertSame(CajuPayBrowserSdk::directApiBaseUrl(), $base);
    }
}
