<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\User;
use App\Services\Stacker\ContainerRestartRequestService;
use App\Support\DockerSetupState;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ContainerRestartRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);

        $path = storage_path('app/stacker/container-restart.json');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function test_restart_endpoint_requires_docker(): void
    {
        $this->partialMock(DockerSetupState::class); // noop — isDocker is static

        putenv('GETFY_DOCKER=false');
        $_ENV['GETFY_DOCKER'] = 'false';
        $_SERVER['GETFY_DOCKER'] = 'false';

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        // Em host de teste sem /.dockerenv e GETFY_DOCKER=false, isDocker() tende a false.
        $service = app(ContainerRestartRequestService::class);
        if ($service->isDockerAvailable()) {
            $this->markTestSkipped('Ambiente de teste reporta Docker ativo.');
        }

        $this->actingAs($admin)
            ->postJson('/plataforma/configuracoes/url-publica/reiniciar-containers', [
                'reason' => 'test',
            ])
            ->assertStatus(422);
    }

    public function test_request_writes_pending_flag_when_docker(): void
    {
        putenv('GETFY_DOCKER=true');
        $_ENV['GETFY_DOCKER'] = 'true';
        $_SERVER['GETFY_DOCKER'] = 'true';

        $service = new ContainerRestartRequestService;
        $this->assertTrue($service->isDockerAvailable());

        $result = $service->request(1, 'test');
        $this->assertSame('pending', $result['status']);
        $this->assertFileExists(storage_path('app/stacker/container-restart.json'));

        $status = $service->status();
        $this->assertSame('pending', $status['status']);
        $this->assertFalse($status['can_request']);

        putenv('GETFY_DOCKER');
        unset($_ENV['GETFY_DOCKER'], $_SERVER['GETFY_DOCKER']);
    }
}
