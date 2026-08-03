<?php

namespace App\Services\CajuPay;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Support\GatewayWebhookUrl;
use Illuminate\Support\Facades\Log;

class CajuPayWebhookBootstrapService
{
    /** @var list<string> */
    public const CHECKOUT_EVENT_TYPES = ['checkout.payment.*', 'pix.payment.*'];

    /** @var list<string> */
    public const PAYOUT_EVENT_TYPES = ['payout.*'];

    public function __construct(
        private CajuPayDriver $driver,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{credentials: array<string, mixed>, warning: ?string, setup_status: array<string, mixed>}
     */
    public function bootstrap(array $credentials, bool $forceRotate = false): array
    {
        $warnings = [];

        $checkout = $this->bootstrapEndpoint(
            $credentials,
            $this->webhookUrl(),
            self::CHECKOUT_EVENT_TYPES,
            endpointIdKey: 'webhook_endpoint_id',
            signingSecretKeys: ['checkout_webhook_signing_secret', 'webhook_signing_secret'],
            forceRotate: $forceRotate,
            label: 'checkout',
        );
        $credentials = $checkout['credentials'];
        if ($checkout['warning'] !== null) {
            $warnings[] = $checkout['warning'];
        }

        try {
            $payoutUrl = $this->payoutWebhookUrl();
        } catch (\Throwable) {
            $warnings[] = 'Webhook CajuPay payout: rota webhooks.cajupay.payout indisponível.';
            $payoutUrl = null;
        }

        if ($payoutUrl !== null) {
            $payout = $this->bootstrapEndpoint(
                $credentials,
                $payoutUrl,
                self::PAYOUT_EVENT_TYPES,
                endpointIdKey: 'payout_webhook_endpoint_id',
                signingSecretKeys: ['payout_webhook_signing_secret', 'webhook_signing_secret', 'checkout_webhook_signing_secret'],
                forceRotate: $forceRotate,
                label: 'payout',
            );
            $credentials = $payout['credentials'];
            if ($payout['warning'] !== null) {
                $warnings[] = $payout['warning'];
            }
        }

        $setupStatus = $this->driver->getWebhookSetupStatus($credentials);

        return [
            'credentials' => $credentials,
            'warning' => $warnings === [] ? null : implode(' ', $warnings),
            'setup_status' => $setupStatus,
        ];
    }

    public function webhookUrl(): string
    {
        return GatewayWebhookUrl::forGateway('cajupay');
    }

    public function payoutWebhookUrl(): string
    {
        return GatewayWebhookUrl::forGateway('cajupay.payout');
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $eventTypes
     * @param  list<string>  $signingSecretKeys  first key is preferred storage target
     * @return array{credentials: array<string, mixed>, warning: ?string}
     */
    private function bootstrapEndpoint(
        array $credentials,
        string $url,
        array $eventTypes,
        string $endpointIdKey,
        array $signingSecretKeys,
        bool $forceRotate,
        string $label,
    ): array {
        $warning = null;
        $hasSecret = $this->hasAnySigningSecret($credentials, $signingSecretKeys);
        $endpointId = trim((string) ($credentials[$endpointIdKey] ?? ''));

        if ($endpointId !== '' && $hasSecret && ! $forceRotate) {
            return [
                'credentials' => $credentials,
                'warning' => null,
            ];
        }

        $rotateIfExists = $forceRotate || ($endpointId !== '' && ! $hasSecret);

        try {
            $reg = $this->driver->registerWebhookEndpointIdempotent(
                $credentials,
                $url,
                $rotateIfExists,
                $eventTypes,
            );
        } catch (\Throwable $e) {
            $warning = 'Webhook '.$label.' ainda não registrado: '.$e->getMessage();
            Log::warning('CajuPayWebhookBootstrapService: registro falhou', [
                'label' => $label,
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return [
                'credentials' => $credentials,
                'warning' => $warning,
            ];
        }

        $credentials[$endpointIdKey] = $reg['endpoint_id'];
        if (! empty($reg['signing_secret'])) {
            $preferred = $signingSecretKeys[0];
            $credentials[$preferred] = $reg['signing_secret'];
            // Keep legacy alias in sync for checkout secrets.
            if ($preferred === 'checkout_webhook_signing_secret') {
                $credentials['webhook_signing_secret'] = $reg['signing_secret'];
            }
        } elseif (! $hasSecret && ($reg['already_exists'] ?? false)) {
            try {
                $reg = $this->driver->registerWebhookEndpointIdempotent(
                    $credentials,
                    $url,
                    true,
                    $eventTypes,
                );
                $credentials[$endpointIdKey] = $reg['endpoint_id'];
                if (! empty($reg['signing_secret'])) {
                    $preferred = $signingSecretKeys[0];
                    $credentials[$preferred] = $reg['signing_secret'];
                    if ($preferred === 'checkout_webhook_signing_secret') {
                        $credentials['webhook_signing_secret'] = $reg['signing_secret'];
                    }
                } else {
                    $warning = 'Endpoint '.$label.' já registrado na CajuPay, mas o signing secret não foi retornado. Use "Rotacionar secret" se necessário.';
                }
            } catch (\Throwable $e) {
                $warning = 'Endpoint '.$label.' já registrado na CajuPay, mas o signing secret não foi retornado. Use "Rotacionar secret" se necessário.';
                Log::warning('CajuPayWebhookBootstrapService: rotate após already_exists falhou', [
                    'label' => $label,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'credentials' => $credentials,
            'warning' => $warning,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $keys
     */
    private function hasAnySigningSecret(array $credentials, array $keys): bool
    {
        foreach ($keys as $key) {
            if (trim((string) ($credentials[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
