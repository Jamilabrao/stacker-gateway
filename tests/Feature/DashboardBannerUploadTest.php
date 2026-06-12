<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DashboardBannerUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_desktop_upload_accepts_exact_dimensions(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $file = UploadedFile::fake()->image('banner-desktop.jpg', 1600, 320);

        $response = $this->actingAs($admin)->post(route('plataforma.settings.dashboard-banners.upload'), [
            'file' => $file,
            'variant' => 'desktop',
        ]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['url']);
        Storage::disk('public')->assertExists('dashboard-banners/'.$file->hashName());
    }

    public function test_desktop_upload_rejects_wrong_dimensions(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $file = UploadedFile::fake()->image('banner-wrong.jpg', 1200, 400);

        $response = $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('plataforma.settings.dashboard-banners.upload'), [
                'file' => $file,
                'variant' => 'desktop',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['file']);
        Storage::disk('public')->assertMissing('dashboard-banners/'.$file->hashName());
    }

    public function test_mobile_upload_accepts_exact_dimensions(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $file = UploadedFile::fake()->image('banner-mobile.jpg', 1200, 420);

        $response = $this->actingAs($admin)->post(route('plataforma.settings.dashboard-banners.upload'), [
            'file' => $file,
            'variant' => 'mobile',
        ]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['url']);
        Storage::disk('public')->assertExists('dashboard-banners/'.$file->hashName());
    }

    public function test_mobile_upload_rejects_wrong_dimensions(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $file = UploadedFile::fake()->image('banner-wrong-mobile.jpg', 1600, 320);

        $response = $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('plataforma.settings.dashboard-banners.upload'), [
                'file' => $file,
                'variant' => 'mobile',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['file']);
    }

    public function test_data_endpoint_includes_specs(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->getJson(route('plataforma.settings.dashboard-banners.data'));

        $response->assertOk()
            ->assertJsonPath('specs.desktop.width', 1600)
            ->assertJsonPath('specs.desktop.height', 320)
            ->assertJsonPath('specs.mobile.width', 1200)
            ->assertJsonPath('specs.mobile.height', 420);
    }
}
