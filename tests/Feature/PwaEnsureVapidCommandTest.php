<?php

namespace Tests\Feature;

use App\Support\PanelPushSettings;
use App\Support\PwaVapidEnvSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestVapidKeys;
use Tests\TestCase;

class PwaEnsureVapidCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestVapidKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPushFeatureTests();
    }

    public function test_ensure_vapid_does_not_rotate_valid_keys(): void
    {
        $keys = $this->configureTestVapidPush();
        PanelPushSettings::storeVapidKeys($keys['publicKey'], $keys['privateKey']);

        $this->artisan('pwa:ensure-vapid')
            ->expectsOutputToContain('Chaves VAPID já válidas.')
            ->assertSuccessful();

        PanelPushSettings::applyToConfig();
        $this->assertSame($keys['publicKey'], config('getfy.pwa.vapid_public'));
        $this->assertSame($keys['privateKey'], config('getfy.pwa.vapid_private'));
    }

    public function test_ensure_vapid_generates_keys_when_missing(): void
    {
        if (! is_file(base_path('.env'))) {
            $this->markTestSkipped('.env ausente no ambiente de teste');
        }

        try {
            PanelPushSettings::generateVapidKeyPair();
        } catch (\Throwable $e) {
            $this->markTestSkipped('OpenSSL indisponível para gerar VAPID: '.$e->getMessage());
        }

        config([
            'getfy.pwa.push_provider' => PanelPushSettings::PROVIDER_VAPID,
            'getfy.pwa.vapid_public' => 'invalid-public',
            'getfy.pwa.vapid_private' => 'invalid-private',
        ]);

        PanelPushSettings::saveGlobal([
            'push_provider' => PanelPushSettings::PROVIDER_VAPID,
            'pwa_vapid_public' => 'invalid-public',
            'pwa_vapid_private' => 'invalid-private',
        ]);
        PanelPushSettings::applyToConfig();

        $this->assertFalse(PanelPushSettings::isVapidConfigured());

        $this->artisan('pwa:ensure-vapid')
            ->expectsOutputToContain('Chaves VAPID geradas e salvas')
            ->assertSuccessful();

        PanelPushSettings::applyToConfig();
        $this->assertTrue(PanelPushSettings::isVapidConfigured());
        $this->assertNotEmpty(config('getfy.pwa.vapid_public'));
        $this->assertNotEmpty(config('getfy.pwa.vapid_private'));

        $pair = PwaVapidEnvSync::readKeyPairFromDotEnv();
        $this->assertNotNull($pair);
        $this->assertSame(config('getfy.pwa.vapid_public'), $pair['publicKey']);
    }
}
