<?php

namespace App\Console\Commands;

use App\Models\PlatformTranslation;
use App\Services\InertiaSharedPropsCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncPlatformTranslationsFromConfig extends Command
{
    protected $signature = 'translations:sync-config
                            {--locale= : Sincronizar apenas um idioma (ex.: pt_BR)}';

    protected $description = 'Atualiza platform_translations com os textos atuais de config/panel_i18n.php e limpa o cache de i18n.';

    public function handle(): int
    {
        if (! Schema::hasTable('platform_translations')) {
            $this->warn('Tabela platform_translations não existe.');

            return self::SUCCESS;
        }

        $group = (string) config('panel_i18n.group', 'seller');
        $locales = (array) config('panel_i18n.locales', []);
        $onlyLocale = $this->option('locale');
        if (is_string($onlyLocale) && $onlyLocale !== '') {
            $locales = array_intersect_key($locales, [$onlyLocale => true]);
        }

        $updated = 0;
        $now = now();

        foreach ($locales as $code => $messages) {
            foreach ((array) $messages as $key => $value) {
                $normalizedKey = trim((string) $key);
                if ($normalizedKey === '') {
                    continue;
                }

                PlatformTranslation::query()->updateOrCreate(
                    [
                        'group' => $group,
                        'key' => $normalizedKey,
                        'locale' => (string) $code,
                    ],
                    [
                        'value' => (string) $value,
                        'updated_at' => $now,
                    ]
                );
                $updated++;
            }

            InertiaSharedPropsCache::forgetI18nMessages((string) $code, $group);
        }

        InertiaSharedPropsCache::forgetI18nMessages(null, $group);
        $this->info("Traduções sincronizadas: {$updated} chave(s). Cache de i18n limpo.");

        return self::SUCCESS;
    }
}
