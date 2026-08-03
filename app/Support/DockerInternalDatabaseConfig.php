<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entre containers Docker o Postgres escuta em 5432.
 * A porta publicada no host (ex.: 5433) só vale para acesso externo (localhost:5433).
 */
class DockerInternalDatabaseConfig
{
    /**
     * Corrige DB_PORT quando o host é o serviço interno `postgres`/`mysql`.
     */
    public static function normalize(): void
    {
        if (! DockerSetupState::isDocker()) {
            return;
        }

        $connection = (string) config('database.default', 'pgsql');
        $host = (string) config("database.connections.{$connection}.host", '');
        $port = (string) config("database.connections.{$connection}.port", '');

        $expected = match ($host) {
            'postgres' => '5432',
            'mysql', 'mariadb' => '3306',
            default => null,
        };

        if ($expected === null || $port === $expected) {
            return;
        }

        config(["database.connections.{$connection}.port" => $expected]);

        try {
            DB::purge($connection);
        } catch (\Throwable) {
            //
        }

        Log::warning('docker_db_port_normalized', [
            'connection' => $connection,
            'host' => $host,
            'from_port' => $port,
            'to_port' => $expected,
            'hint' => 'Use a porta interna do serviço Docker (postgres:5432). A porta publicada no host (ex. 5433) não funciona entre containers.',
        ]);
    }
}
