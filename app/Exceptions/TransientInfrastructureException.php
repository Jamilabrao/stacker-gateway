<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Falha temporária de infraestrutura (Postgres/Redis/rede).
 * O job deve fazer retry sem marcar a campanha como enviada/falha definitiva.
 */
class TransientInfrastructureException extends RuntimeException
{
    public static function fromThrowable(Throwable $e): self
    {
        return new self(mb_substr($e->getMessage(), 0, 2000), (int) $e->getCode(), $e);
    }

    public static function matches(Throwable $e): bool
    {
        if ($e instanceof self) {
            return true;
        }

        $class = $e::class;
        if (is_a($e, \Illuminate\Database\QueryException::class)
            || is_a($e, \PDOException::class)
            || is_a($e, \RedisException::class)
            || str_contains($class, 'Predis\\Connection')
            || str_contains($class, 'Redis\\Connection')) {
            return true;
        }

        $msg = strtolower($e->getMessage());
        $needles = [
            'connection refused',
            'connection timed out',
            'could not connect',
            'server has gone away',
            'too many connections',
            'sqlstate[08006]',
            'sqlstate[08001]',
            'sqlstate[57p01]',
            'sqlstate[hy000]',
            'read error on connection',
            'broken pipe',
            'no connection to the server',
            'postgres:',
            'redis',
        ];

        foreach ($needles as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }

        return false;
    }
}
