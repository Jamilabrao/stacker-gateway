<?php

namespace Tests\Unit;

use App\Support\PanelPushSettings;
use PHPUnit\Framework\TestCase;

class PanelPushSettingsTest extends TestCase
{
    public function test_normalize_provider(): void
    {
        $this->assertSame('fcm', PanelPushSettings::normalizeProvider('fcm'));
        $this->assertSame('vapid', PanelPushSettings::normalizeProvider('vapid'));
        $this->assertSame('vapid', PanelPushSettings::normalizeProvider('unknown'));
    }

    public function test_merge_with_env_fallback_uses_stored_vapid(): void
    {
        $merged = PanelPushSettings::mergeWithEnvFallback([
            'push_provider' => 'vapid',
            'pwa_vapid_public' => 'BH8test',
        ]);

        $this->assertSame('vapid', $merged['push_provider']);
        $this->assertSame('BH8test', $merged['pwa_vapid_public']);
    }

    public function test_data_has_fcm_credentials_requires_all_fields(): void
    {
        $this->assertFalse(PanelPushSettings::dataHasFcmCredentials([
            'firebase_project_id' => 'p',
        ]));
        $this->assertTrue(PanelPushSettings::dataHasFcmCredentials([
            'firebase_project_id' => 'p',
            'firebase_api_key' => 'k',
            'firebase_messaging_sender_id' => 's',
            'firebase_app_id' => 'a',
            'firebase_web_vapid_key' => 'v',
            'firebase_service_account' => '{}',
        ]));
    }
}
