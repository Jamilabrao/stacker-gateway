<?php

namespace Tests\Unit;

use App\Exceptions\TransientInfrastructureException;
use App\Support\DockerInternalDatabaseConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DockerInternalDatabaseConfigTest extends TestCase
{
    public function test_normalizes_postgres_host_port_5433_to_5432_in_docker(): void
    {
        putenv('GETFY_DOCKER=true');
        $_ENV['GETFY_DOCKER'] = 'true';

        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.host', 'postgres');
        Config::set('database.connections.pgsql.port', '5433');

        DockerInternalDatabaseConfig::normalize();

        $this->assertSame('5432', (string) config('database.connections.pgsql.port'));

        putenv('GETFY_DOCKER');
        unset($_ENV['GETFY_DOCKER']);
    }

    public function test_does_not_change_external_host_port(): void
    {
        putenv('GETFY_DOCKER=true');
        $_ENV['GETFY_DOCKER'] = 'true';

        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.host', 'db.example.com');
        Config::set('database.connections.pgsql.port', '5433');

        DockerInternalDatabaseConfig::normalize();

        $this->assertSame('5433', (string) config('database.connections.pgsql.port'));

        putenv('GETFY_DOCKER');
        unset($_ENV['GETFY_DOCKER']);
    }
}

class TransientInfrastructureExceptionTest extends TestCase
{
    public function test_matches_connection_refused(): void
    {
        $e = new \PDOException('SQLSTATE[08006] Connection refused: postgres:5433');
        $this->assertTrue(TransientInfrastructureException::matches($e));
    }

    public function test_does_not_match_validation_errors(): void
    {
        $e = new \InvalidArgumentException('Título inválido');
        $this->assertFalse(TransientInfrastructureException::matches($e));
    }
}
