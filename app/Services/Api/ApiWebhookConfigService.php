<?php

namespace App\Services\Api;

use App\Models\ApiApplication;
use App\Support\ApiWebhookEvents;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApiWebhookConfigService
{
    /** Sentinel: do not change stored webhook_events. */
    public const EVENTS_UNCHANGED = '__events_unchanged__';

    /**
     * @param  list<string>|null|self::EVENTS_UNCHANGED  $events
     */
    public function updateFromPanel(
        ApiApplication $app,
        ?string $url,
        array|string|null $events,
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
        ];

        if ($events !== self::EVENTS_UNCHANGED) {
            $payload['webhook_events'] = $events;
        }

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
            'webhook_events' => ApiWebhookEvents::allSubscription(),
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

        $app->update([
            'webhook_url' => $url,
            'webhook_secret' => $secret,
            'webhook_enabled' => $enabled,
        ]);

        $this->ensureAllEventsSubscribed($app);

        return new WebhookProvisionResult($app->fresh(), $revealed);
    }

    public function ensureAllEventsSubscribed(ApiApplication $app): void
    {
        ApiApplication::query()
            ->whereKey($app->id)
            ->update(['webhook_events' => ApiWebhookEvents::allSubscription()]);
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
        if (ApiWebhookEvents::isAllSubscription($events)) {
            return ApiWebhookEvents::allSubscription();
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseArray(ApiApplication $app, ?string $revealedSecret = null): array
    {
        $events = $app->webhook_events;
        $allEvents = ApiWebhookEvents::isAllSubscription(is_array($events) ? $events : null);
        $hasSecret = ($app->webhook_secret ?? '') !== '';
        $enabled = Schema::hasColumn($app->getTable(), 'webhook_enabled')
            ? (bool) ($app->webhook_enabled ?? true)
            : true;

        $response = [
            'webhook_url' => $app->webhook_url,
            'webhook_enabled' => $enabled,
            'webhook_events' => $allEvents ? null : $events,
            'events_mode' => $allEvents ? 'all' : 'selected',
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
