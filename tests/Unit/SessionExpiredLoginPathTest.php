<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Espelha a lógica de resources/js/lib/sessionExpired.js (smoke do handler 419).
 * O front faz hard navigate para login?expired=1 em vez de reload silencioso.
 */
class SessionExpiredLoginPathTest extends TestCase
{
    #[DataProvider('pathsProvider')]
    public function test_resolve_login_path_for_expired_session(string $pathname, string $expectedPrefix): void
    {
        $resolved = $this->resolveLoginPathForExpiredSession($pathname, '');
        $this->assertStringStartsWith($expectedPrefix, $resolved);
        $this->assertStringContainsString('expired=1', $resolved);
    }

    public static function pathsProvider(): array
    {
        return [
            'seller dashboard' => ['/dashboard', '/login?expired=1'],
            'seller login' => ['/login', '/login?'],
            'platform' => ['/plataforma/dashboard', '/plataforma/login?expired=1'],
            'platform login' => ['/plataforma/login', '/plataforma/login?expired=1'],
            'member path' => ['/m/abcd12ef/modulos', '/m/abcd12ef/login?expired=1'],
            'member login path' => ['/m/abcd12ef/login', '/m/abcd12ef/login?expired=1'],
            'custom host login' => ['/login', '/login?'],
        ];
    }

    private function resolveLoginPathForExpiredSession(string $pathname, string $search): string
    {
        $path = $pathname !== '' ? $pathname : '/';

        if (str_starts_with($path, '/plataforma')) {
            return '/plataforma/login?expired=1';
        }

        if (preg_match('#^/m/([a-zA-Z0-9]{6,16})(?:/|$)#', $path, $memberMatch)) {
            return '/m/'.$memberMatch[1].'/login?expired=1';
        }

        if (preg_match('#/login/?$#', $path) || $path === '/login') {
            parse_str(ltrim($search, '?'), $params);
            $params['expired'] = '1';
            $params['_'] = '1';

            return strtok($path, '?').'?'.http_build_query($params);
        }

        return '/login?expired=1';
    }
}
