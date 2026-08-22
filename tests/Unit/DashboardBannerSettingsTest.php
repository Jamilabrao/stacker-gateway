<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\User;
use App\Support\DashboardBannerSettings;
use Illuminate\Http\Request;
use Tests\TestCase;

class DashboardBannerSettingsTest extends TestCase
{
    public function test_resolves_legacy_localhost_banner_urls_to_current_https_host(): void
    {
        Setting::set(DashboardBannerSettings::KEY, [
            [
                'id' => 'banner-1',
                'title' => 'Promo',
                'desktop_url' => 'http://localhost:8085/storage/dashboard-banners/desktop.jpg',
                'mobile_url' => 'http://localhost:8085/storage/dashboard-banners/mobile.jpg',
                'active' => true,
                'sort_order' => 1,
            ],
        ], null);

        $request = Request::create(
            'https://meu-dominio.test/dashboard',
            'GET',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $this->app->instance('request', $request);

        $banners = DashboardBannerSettings::banners(activeOnly: true, resolveUrls: true);

        $this->assertCount(1, $banners);
        $this->assertSame(
            'https://meu-dominio.test/storage/dashboard-banners/desktop.jpg',
            $banners[0]['desktop_url']
        );
        $this->assertSame(
            'https://meu-dominio.test/storage/dashboard-banners/mobile.jpg',
            $banners[0]['mobile_url']
        );
    }

    public function test_active_only_filters_inactive_banners(): void
    {
        Setting::set(DashboardBannerSettings::KEY, [
            [
                'id' => 'banner-hidden',
                'title' => 'Oculto',
                'desktop_url' => 'dashboard-banners/hidden.jpg',
                'mobile_url' => '',
                'active' => false,
                'sort_order' => 1,
            ],
        ], null);

        $request = Request::create(
            'https://meu-dominio.test/dashboard',
            'GET',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $this->app->instance('request', $request);

        $banners = DashboardBannerSettings::banners(activeOnly: true, resolveUrls: true);

        $this->assertSame([], $banners);
    }

    public function test_resolves_r2_banner_urls_from_global_storage_when_infoproducer_is_logged_in(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $seller->update(['tenant_id' => $seller->id]);
        $this->actingAs($seller);

        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'test-key', null);
        Setting::set('storage_s3_secret', encrypt('test-secret'), null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'https://pub-abc123.r2.dev', null);

        Setting::set(DashboardBannerSettings::KEY, [
            [
                'id' => 'banner-1',
                'title' => 'Promo',
                'desktop_url' => 'dashboard-banners/desktop.jpg',
                'mobile_url' => 'dashboard-banners/mobile.jpg',
                'active' => true,
                'sort_order' => 1,
            ],
        ], null);

        $banners = DashboardBannerSettings::banners(activeOnly: true, resolveUrls: true);

        $this->assertCount(1, $banners);
        $this->assertSame(
            'https://pub-abc123.r2.dev/dashboard-banners/desktop.jpg',
            $banners[0]['desktop_url']
        );
        $this->assertSame(
            'https://pub-abc123.r2.dev/dashboard-banners/mobile.jpg',
            $banners[0]['mobile_url']
        );
    }
}
