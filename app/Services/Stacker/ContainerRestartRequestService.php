<?php

namespace App\Services\Stacker;

use App\Support\DockerSetupState;
use Illuminate\Support\Facades\File;

/**
 * Pedido de reinício de containers via flag em storage (consumido pelo stacker-agent).
 */
class ContainerRestartRequestService
{
    public const RELATIVE_PATH = 'stacker/container-restart.json';

    public function path(): string
    {
        return storage_path('app/'.self::RELATIVE_PATH);
    }

    public function isDockerAvailable(): bool
    {
        return DockerSetupState::isDocker();
    }

    /**
     * @return array{
     *     status: string,
     *     requested_at: ?string,
     *     started_at: ?string,
     *     finished_at: ?string,
     *     message: ?string,
     *     reason: ?string,
     *     requested_by: ?int,
     *     docker_mode: bool,
     *     can_request: bool
     * }
     */
    public function status(): array
    {
        $payload = $this->read();
        $status = (string) ($payload['status'] ?? 'idle');

        return [
            'status' => $status !== '' ? $status : 'idle',
            'requested_at' => $payload['requested_at'] ?? null,
            'started_at' => $payload['started_at'] ?? null,
            'finished_at' => $payload['finished_at'] ?? null,
            'message' => $payload['message'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'requested_by' => isset($payload['requested_by']) ? (int) $payload['requested_by'] : null,
            'docker_mode' => $this->isDockerAvailable(),
            'can_request' => $this->isDockerAvailable() && ! in_array($status, ['pending', 'running'], true),
        ];
    }

    /**
     * @return array{status: string, message: string, docker_mode: bool, can_request: bool}
     */
    public function request(?int $userId = null, string $reason = 'manual'): array
    {
        if (! $this->isDockerAvailable()) {
            return [
                'status' => 'unavailable',
                'message' => 'Reinício de containers só está disponível em instalação Docker (com stacker-agent).',
                'docker_mode' => false,
                'can_request' => false,
            ];
        }

        $current = $this->status();
        if (! $current['can_request']) {
            return [
                'status' => $current['status'],
                'message' => 'Já existe um reinício em andamento. Aguarde a conclusão.',
                'docker_mode' => true,
                'can_request' => false,
            ];
        }

        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        $payload = [
            'status' => 'pending',
            'requested_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'message' => 'Aguardando o stacker-agent aplicar o reinício…',
            'reason' => $reason,
            'requested_by' => $userId,
            'logs' => '',
        ];

        $this->write($payload);

        return [
            'status' => 'pending',
            'message' => 'Reinício solicitado. O agente aplica em alguns segundos.',
            'docker_mode' => true,
            'can_request' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        $raw = (string) @file_get_contents($path);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(array $payload): void
    {
        file_put_contents(
            $this->path(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
