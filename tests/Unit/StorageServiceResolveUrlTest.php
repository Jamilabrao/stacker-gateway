<?php

namespace Tests\Unit;

use App\Services\StorageService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StorageServiceResolveUrlTest extends TestCase
{
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
}
