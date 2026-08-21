<?php

namespace App\Http\Middleware;

use App\Services\InboundGatewayWebhookRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogInboundGatewayWebhook
{
    public function __construct(
        private readonly InboundGatewayWebhookRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->recorder->capture($request);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $body = $response->getContent();
        $this->recorder->markResponse(
            $request,
            $response->getStatusCode(),
            is_string($body) ? $body : null,
        );
    }
}
