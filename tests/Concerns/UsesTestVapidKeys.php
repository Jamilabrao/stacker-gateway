<?php

namespace Tests\Concerns;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use App\Support\PanelPushSettings;

trait UsesTestVapidKeys
{
    protected function setUpPushFeatureTests(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);
    }
    /**
     * Par VAPID válido para testes (evita depender de OpenSSL em CI/Windows).
     *
     * @return array{publicKey: string, privateKey: string}
     */
    protected function testVapidKeyPair(): array
    {
        return [
            'publicKey' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
            'privateKey' => 'UUxI4O8-FbRouBdNE7vfBR7HyzXwwKtnIzfEiAQgpwY',
        ];
    }

    protected function configureTestVapidPush(): array
    {
        $keys = $this->testVapidKeyPair();
        config([
            'getfy.pwa.push_provider' => PanelPushSettings::PROVIDER_VAPID,
            'getfy.pwa.vapid_public' => $keys['publicKey'],
            'getfy.pwa.vapid_private' => $keys['privateKey'],
        ]);

        return $keys;
    }

    protected function createSellerUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'kyc_status' => User::KYC_APPROVED,
        ], $overrides));
    }
}
