<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe o instalador web: desabilitado após install ou via INSTALLER_ENABLED=false.
 */
class EnsureInstallerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isInstalled()) {
            abort(404);
        }

        if (! config('getfy.installer.enabled', true)) {
            abort(404);
        }

        $expected = config('getfy.installer.token');
        if (is_string($expected) && $expected !== '') {
            $provided = $request->query('installer_token')
                ?? $request->header('X-Installer-Token')
                ?? $request->input('installer_token');
            if (! is_string($provided) || ! hash_equals($expected, $provided)) {
                abort(403, 'Token de instalação inválido.');
            }
        }

        return $next($request);
    }

    private function isInstalled(): bool
    {
        return (bool) config('getfy.installed', false);
    }
}
