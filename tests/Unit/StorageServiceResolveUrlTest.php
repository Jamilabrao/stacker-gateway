<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\StorageService;
use App\Support\RemoteStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StorageServiceResolveUrlTest extends TestCase
{
    use RefreshDatabase;
    public function test_resolve_public_url_rewrites_legacy_local_storage_url_to_current_app_storage(): void
    {
        Config::set('app.url', 'https://loja.example.com');
        Config::set('filesystems.disks.public.url', 'https://loja.example.com/storage');

        $service = new StorageService(null);
        $legacy = 'https://antigo.example.com/storage/member-area/5/capa.jpg';
        $resolved = $service->resolvePublicUrl($legacy);

        $this->assertStringContainsString('/storage/member-area/5/capa.jpg', $resolved);
        $this->assertStringNotContainsString('antigo.example.com', $resolved);
    }

    public function test_resolve_public_url_handles_relative_member_area_path(): void
    {
        Config::set('app.url', 'https://loja.example.com');

        $service = new StorageService(null);
        $resolved = $service->resolvePublicUrl('member-area/5/capa.jpg');

        $this->assertStringContainsString('member-area/5/capa.jpg', $resolved);
    }

    public function test_to_storage_path_strips_storage_prefix(): void
    {
        $service = new StorageService(null);
        $path = $service->toStoragePath('https://loja.example.com/storage/member-area/1/x.png');

        $this->assertSame('member-area/1/x.png', $path);
    }

    public function test_resolve_public_url_uses_r2_public_base_not_api_endpoint(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'test-key', null);
        Setting::set('storage_s3_secret', encrypt('test-secret'), null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'https://pub-abc123.r2.dev', null);
        Setting::set('storage_s3_region', 'auto', null);

        $service = new StorageService(null);
        $resolved = $service->resolvePublicUrl('products/foto.jpg');

        $this->assertSame('https://pub-abc123.r2.dev/products/foto.jpg', $resolved);
    }

    public function test_resolve_public_url_rewrites_stored_api_endpoint_url(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'test-key', null);
        Setting::set('storage_s3_secret', encrypt('test-secret'), null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'https://cdn.loja.com', null);

        $service = new StorageService(null);
        $bad = 'https://acc.r2.cloudflarestorage.com/my-bucket/products/x.png';
        $resolved = $service->resolvePublicUrl($bad);

        $this->assertSame('https://cdn.loja.com/products/x.png', $resolved);
    }

    public function test_remote_storage_extract_key_from_r2_api_url(): void
    {
        $key = RemoteStorage::extractObjectKeyFromUrl(
            'https://acc.r2.cloudflarestorage.com/my-bucket/member-area/1.png',
            'my-bucket'
        );

        $this->assertSame('member-area/1.png', $key);
    }

    public function test_normalize_public_base_url_adds_https_when_missing(): void
    {
        $this->assertSame(
            'https://media.valuxpay.com',
            RemoteStorage::normalizePublicBaseUrl('media.valuxpay.com')
        );
    }

    public function test_resolve_public_url_with_host_only_base_not_app_relative(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'key', null);
        Setting::set('storage_s3_secret', encrypt('secret'), null);
        Setting::set('storage_s3_bucket', 'bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'media.valuxpay.com', null);

        $service = new StorageService(null);
        $resolved = $service->resolvePublicUrl('avatars/photo.png');

        $this->assertSame('https://media.valuxpay.com/avatars/photo.png', $resolved);
    }

    public function test_repair_malformed_app_path_with_embedded_cdn_host(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'key', null);
        Setting::set('storage_s3_secret', encrypt('secret'), null);
        Setting::set('storage_s3_bucket', 'bucket', null);
        Setting::set('storage_s3_url', 'https://media.valuxpay.com', null);

        $service = new StorageService(null);
        $broken = 'https://app.valuxpay.com/plataforma/media.valuxpay.com/avatars/Z.png';
        $resolved = $service->resolvePublicUrl($broken);

        $this->assertSame('https://media.valuxpay.com/avatars/Z.png', $resolved);
    }
}
