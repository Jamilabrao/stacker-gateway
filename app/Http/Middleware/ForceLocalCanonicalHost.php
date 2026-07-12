<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Em local, localhost e 127.0.0.1 são hosts diferentes para cookies de sessão.
 * Redireciona 127.0.0.1 → localhost para evitar login que "carrega e não entra".
 */
class ForceLocalCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        if ($host !== '127.0.0.1' && $host !== '::1') {
            return $next($request);
        }

        $canonical = 'localhost';
        $port = $request->getPort();
        $portSuffix = in_array($port, [80, 443], true) ? '' : ':'.$port;
        $target = $request->getScheme().'://'.$canonical.$portSuffix.$request->getRequestUri();

        return redirect()->to($target, 302);
    }
}
