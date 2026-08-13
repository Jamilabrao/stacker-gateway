<?php

namespace Tests\Unit;

use App\Services\MetricsTracking\MetricsClientParser;
use Illuminate\Http\Request;
use Tests\TestCase;

class MetricsClientParserCloudflareTest extends TestCase
{
    public function test_resolve_client_ip_prefers_cloudflare_connecting_ip(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '172.18.0.10',
            'HTTP_CF_CONNECTING_IP' => '201.17.45.9',
        ]);

        $this->assertSame('201.17.45.9', MetricsClientParser::resolveClientIp($request));
    }

    public function test_resolve_client_ip_ignores_invalid_cloudflare_header(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
        ]);

        $this->assertSame('203.0.113.10', MetricsClientParser::resolveClientIp($request));
    }

    public function test_cloudflare_country_header_maps_to_ip_api_style_name(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => 'BR',
            'HTTP_CF_IPCITY' => 'Sao%20Paulo',
            'HTTP_CF_REGION' => 'Sao%20Paulo',
            'HTTP_CF_IPLATITUDE' => '-23.55',
            'HTTP_CF_IPLONGITUDE' => '-46.63',
        ]);

        $geo = MetricsClientParser::geoFromCloudflareHeaders($request);

        $this->assertNotNull($geo);
        $this->assertSame('Brazil', $geo['country']);
        $this->assertSame('Sao Paulo', $geo['city']);
        $this->assertSame('Sao Paulo', $geo['region']);
        $this->assertEqualsWithDelta(-23.55, (float) $geo['latitude'], 0.001);
        $this->assertEqualsWithDelta(-46.63, (float) $geo['longitude'], 0.001);
    }

    public function test_cloudflare_xx_and_tor_country_are_ignored(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => 'XX',
        ]);

        $this->assertNull(MetricsClientParser::geoFromCloudflareHeaders($request));
    }

    public function test_no_cloudflare_headers_returns_null_geo(): void
    {
        $request = Request::create('/', 'POST');

        $this->assertNull(MetricsClientParser::geoFromCloudflareHeaders($request));
        $this->assertSame('127.0.0.1', MetricsClientParser::resolveClientIp($request));
    }
}
