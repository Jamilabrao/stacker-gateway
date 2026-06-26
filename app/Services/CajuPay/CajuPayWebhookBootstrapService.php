<?php

namespace App\Services\CajuPay;

use App\Gateways\CajuPay\CajuPayDriver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class CajuPayWebhookBootstrapService
{
    public function __construct(
        private CajuPayDriver $driver,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{credentials: array<string, mixed>, warning: ?string, setup_status: array<string, mixed>}
     */
    public function bootstrap(array $credentials, bool $forceRotate = false): array
    {
        $warning = null;

        try {
            $url = $this->webhookUrl();
        } catch (\Throwable) {
            return [
                'credentials' => $credentials,
                'warning' => 'Webhook CajuPay: rota webhooks.cajupay indisponível.',
                'setup_status' => [],
            ];
        }

        $hasSecret = $this->hasSigningSecret($credentials);
        $endpointId = trim((string) ($credentials['webhook_endpoint_id'] ?? ''));

        if ($endpointId !== '' && $hasSecret && ! $forceRotate) {
            $setupStatus = $this->driver->getWebhookSetupStatus($credentials);

            return [
                'credentials' => $credentials,
                'warning' => null,
                'setup_status' => $setupStatus,
            ];
        }

        $rotateIfExists = $forceRotate || ($endpointId !== '' && ! $hasSecret);

        try {
            $reg = $this->driver->registerWebhookEndpointIdempotent($credentials, $url, $rotateIfExists);
        } catch (\Throwable $e) {
            $warning = 'Webhook ainda não registrado: '.$e->getMessage();
            Log::warning('CajuPayWebhookBootstrapService: registro falhou', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return [
                'credentials' => $credentials,
                'warning' => $warning,
                'setup_status' => [],
            ];
        }

        $credentials['webhook_endpoint_id'] = $reg['endpoint_id'];
        if (! empty($reg['signing_secret'])) {
            $credentials['checkout_webhook_signing_secret'] = $reg['signing_secret'];
            $credentials['webhook_signing_secret'] = $reg['signing_secret'];
        } elseif (! $hasSecret && ($reg['already_exists'] ?? false)) {
            try {
                $reg = $this->driver->registerWebhookEndpointIdempotent($credentials, $url, true);
                $credentials['webhook_endpoint_id'] = $reg['endpoint_id'];
                if (! empty($reg['signing_secret'])) {
                    $credentials['checkout_webhook_signing_secret'] = $reg['signing_secret'];
                    $credentials['webhook_signing_secret'] = $reg['signing_secret'];
                } else {
                    $warning = 'Endpoint já registrado na CajuPay, mas o signing secret não foi retornado. Use "Rotacionar secret" se necessário.';
                }
            } catch (\Throwable $e) {
                $warning = 'Endpoint já registrado na CajuPay, mas o signing secret não foi retornado. Use "Rotacionar secret" se necessário.';
                Log::warning('CajuPayWebhookBootstrapService: rotate após already_exists falhou', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $setupStatus = $this->driver->getWebhookSetupStatus($credentials);

        return [
            'credentials' => $credentials,
            'warning' => $warning,
            'setup_status' => $setupStatus,
        ];
    }

    public function webhookUrl(): string
    {
        if (! Route::has('webhooks.cajupay')) {
            throw new \RuntimeException('Rota webhooks.cajupay indisponível.');
        }

        $publicBase = trim((string) (config('getfy.webhook_public_url') ?? ''));
        if ($publicBase !== '') {
            return rtrim($publicBase, '/').'/webhooks/gateways/cajupay';
        }

        return route('webhooks.cajupay');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function hasSigningSecret(array $credentials): bool
    {
        foreach (['checkout_webhook_signing_secret', 'webhook_signing_secret'] as $key) {
            if (trim((string) ($credentials[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
