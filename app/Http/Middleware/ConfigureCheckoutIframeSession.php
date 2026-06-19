<?php

namespace App\Http\Middleware;

use App\Support\CheckoutEmbed;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureCheckoutIframeSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (CheckoutEmbed::isEmbeddableRequest($request)) {
            config([
                'session.same_site' => 'none',
                'session.secure' => true,
                'session.partitioned' => true,
            ]);
        }

        return $next($request);
    }
}
