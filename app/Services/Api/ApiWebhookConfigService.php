<?php

namespace App\Services\Api;

use App\Models\ApiApplication;
use App\Support\ApiWebhookEvents;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApiWebhookConfigService
{
    /**
     * @param  list<string>|null  $events
     */
    public function updateFromPanel(
        ApiApplication $app,
        ?string $url,
        ?array $events,
        ?string $providedSecret,
        bool $hadSecret,
        ?bool $webhookEnabled = null,
        ?bool $isActive = null,
    ): WebhookProvisionResult {
        $secret = strlen((string) $providedSecret) > 0
            ? $providedSecret
            : $app->webhook_secret;

        $newSecretGenerated = false;
        if (is_string($url) && $url !== '' && ! $secret) {
            $secret = $this->generateSecret();
            $newSecretGenerated = true;
        }

        $payload = [
            'webhook_url' => $url ?: null,
            'webhook_events' => $events,
        ];

        if (is_string($url) && $url !== '') {
            $payload['webhook_secret'] = $secret;
        }

        if ($isActive !== null) {
            $payload['is_active'] = $isActive;
        }

        if ($webhookEnabled !== null) {
            $payload['webhook_enabled'] = $webhookEnabled;
        }

        $app->update($payload);

        $revealed = null;
        if ($newSecretGenerated || (strlen((string) $providedSecret) > 0 && ! $hadSecret)) {
            $revealed = $secret;
        }

        return new WebhookProvisionResult($app->fresh(), $revealed);
    }

    public function clearWebhook(ApiApplication $app): void
    {
        $app->update([
            'webhook_url' => null,
            'webhook_secret' => null,
            'webhook_events' => null,
            'webhook_enabled' => true,
        ]);
    }

    public function provisionForApi(
        ApiApplication $app,
        string $url,
        bool $enabled = true,
        bool $rotateSecret = false,
    ): WebhookProvisionResult {
        $previousUrl = (string) ($app->webhook_url ?? '');
        $urlChanged = $previousUrl !== $url;
        $existingSecret = (string) ($app->webhook_secret ?? '');

        $revealed = null;
        $secret = $existingSecret;

        if ($rotateSecret || $secret === '' || $urlChanged) {
            $secret = $this->generateSecret();
            $revealed = $secret;
        }

        $payload = [
            'webhook_url' => $url,
            'webhook_events' => null,
            'webhook_secret' => $secret,
            'webhook_enabled' => $enabled,
        ];

        $app->update($payload);

        return new WebhookProvisionResult($app->fresh(), $revealed);
    }

    public function rotateSecret(ApiApplication $app): string
    {
        if (! $app->webhook_url) {
            throw new \InvalidArgumentException('Configure uma URL de webhook antes de rotacionar o secret.');
        }

        $secret = $this->generateSecret();
        $app->update(['webhook_secret' => $secret]);

        return $secret;
    }

    /**
     * @param  list<string>|null  $events
     * @return list<string>|null
     */
    public function normalizePanelEvents(?array $events): ?array
    {
        if (! is_array($events)) {
            return null;
        }

        $events = array_values(array_unique($events));
        $allEvents = ApiWebhookEvents::all();
        if (count($events) === 0 || count($events) >= count($allEvents)) {
            return null;
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseArray(ApiApplication $app, ?string $revealedSecret = null): array
    {
        $events = $app->webhook_events;
        $hasSecret = ($app->webhook_secret ?? '') !== '';
        $enabled = Schema::hasColumn($app->getTable(), 'webhook_enabled')
            ? (bool) ($app->webhook_enabled ?? true)
            : true;

        $response = [
            'webhook_url' => $app->webhook_url,
            'webhook_enabled' => $enabled,
            'webhook_events' => $events,
            'events_mode' => $events === null ? 'all' : 'selected',
            'has_secret' => $hasSecret,
        ];

        if ($revealedSecret !== null && $revealedSecret !== '') {
            $response['webhook_secret'] = $revealedSecret;
        }

        return $response;
    }

    private function generateSecret(): string
    {
        return Str::random(32);
    }
}
