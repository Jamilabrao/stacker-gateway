<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersMetaPixelTest extends TestCase
{
    public function test_production_csp_allows_meta_pixel_connect_endpoints(): void
    {
        config(['app.env' => 'production']);

        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp);
        $this->assertStringContainsString('connect.facebook.net', $csp);
        $this->assertStringContainsString('https://www.facebook.com', $csp);
        $this->assertStringContainsString('https://graph.facebook.com', $csp);
        $this->assertStringContainsString('https://*.a.run.app', $csp);
        $this->assertStringContainsString('https://*.run.app', $csp);
        $this->assertStringContainsString('https://*.on.aws', $csp);
    }

    public function test_production_csp_allows_meta_pixel_frame_src(): void
    {
        config(['app.env' => 'production']);

        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp);
        $this->assertStringContainsString('frame-src', $csp);
        $this->assertStringContainsString('https://www.facebook.com', $csp);
        $this->assertStringContainsString('https://*.facebook.com', $csp);
    }

    public function test_production_csp_allows_cielo_silent_order_post(): void
    {
        config(['app.env' => 'production']);

        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp);
        $this->assertStringContainsString('https://*.pagador.com.br', $csp);
        $this->assertStringContainsString('https://*.cieloecommerce.cielo.com.br', $csp);
    }
}
