<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\SalesAchievement;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesAchievementsImageUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_uploaded_achievement_image_uses_current_https_host(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $achievement = SalesAchievement::query()->create([
            'slug' => 'meta-10k',
            'name' => 'Meta 10k',
            'threshold' => 10000,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $request = Request::create(
            'https://meu-dominio.test/plataforma/conquistas/'.$achievement->id.'/image',
            'POST',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $request->files->set('file', UploadedFile::fake()->image('badge.png'));
        $this->app->instance('request', $request);

        $response = $this->actingAs($admin)->post(
            '/plataforma/conquistas/'.$achievement->id.'/image',
            ['file' => UploadedFile::fake()->image('badge.png')]
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertStringStartsWith('https://meu-dominio.test/storage/', $response->json('url'));

        $achievement->refresh();
        $this->assertStringStartsWith('conquistas/', (string) $achievement->image);
        $this->assertStringNotContainsString('localhost', (string) $achievement->image);
    }

    public function test_legacy_localhost_image_is_resolved_on_index(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        SalesAchievement::query()->create([
            'slug' => 'meta-legacy',
            'name' => 'Meta legada',
            'threshold' => 5000,
            'image' => 'http://localhost:8085/storage/conquistas/legacy.png',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $request = Request::create(
            'https://meu-dominio.test/plataforma/conquistas',
            'GET',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $this->app->instance('request', $request);

        $response = $this->actingAs($admin)->get('/plataforma/conquistas');

        $response->assertOk();
        $achievements = $response->viewData('page')['props']['achievements'] ?? [];
        $this->assertNotEmpty($achievements);
        $this->assertSame(
            'https://meu-dominio.test/storage/conquistas/legacy.png',
            $achievements[0]['image'] ?? null
        );
    }
}
