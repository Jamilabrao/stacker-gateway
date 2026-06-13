<?php

namespace App\Console\Commands;

use App\Support\PanelPushSettings;
use App\Support\PwaVapidEnvSync;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class EnsureVapidKeysCommand extends Command
{
    protected $signature = 'pwa:ensure-vapid';

    protected $description = 'Garante chaves VAPID válidas para push PWA (idempotente; não rotaciona chaves existentes)';

    public function handle(): int
    {
        try {
            PanelPushSettings::applyToConfig();
        } catch (\Throwable $e) {
            $this->warn('Branding indisponível: '.$e->getMessage());
        }

        if (PanelPushSettings::activeProvider() === PanelPushSettings::PROVIDER_FCM
            && PanelPushSettings::isFcmConfigured()) {
            $this->info('Push via Firebase FCM configurado; VAPID do painel não é necessário.');

            return self::SUCCESS;
        }

        if (PanelPushSettings::isVapidConfigured()) {
            PwaVapidEnvSync::syncFromDotEnv();
            $this->info('Chaves VAPID já válidas.');

            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->error('Arquivo .env não encontrado.');

            return self::FAILURE;
        }

        try {
            $keys = PanelPushSettings::generateVapidKeyPair();
        } catch (\Throwable $e) {
            $this->error('Falha ao gerar chaves: '.$e->getMessage());

            return self::FAILURE;
        }

        $publicKey = $keys['publicKey'];
        $privateKey = $keys['privateKey'];

        try {
            VAPID::validate([
                'subject' => 'mailto:validate@example.invalid',
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
        } catch (\Throwable $e) {
            $this->error('Chaves geradas falharam na validação: '.$e->getMessage());

            return self::FAILURE;
        }

        PwaVapidEnvSync::writeKeysToDotEnv($publicKey, $privateKey);

        try {
            PanelPushSettings::storeVapidKeys($publicKey, $privateKey);
        } catch (\Throwable $e) {
            $this->warn('Chaves salvas no .env, mas falhou ao gravar no branding: '.$e->getMessage());
        }

        $this->info('Chaves VAPID geradas e salvas (branding + .env + .docker/pwa_vapid.env).');

        return self::SUCCESS;
    }
}
