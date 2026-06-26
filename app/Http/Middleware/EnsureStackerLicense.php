<?php

namespace App\Http\Middleware;

use App\Services\Stacker\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureStackerLicense
{
    public function __construct(private LicenseService $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->license->isDisabled() || $this->license->isLicenseValid()) {
            return $next($request);
        }

        Log::warning('stacker.license.blocked', [
            'path' => $request->path(),
            'ip' => $request->ip(),
            'blocked' => $this->license->isBlocked(),
        ]);

        abort(404);
    }
}
