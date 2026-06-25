<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class DockerEnvBootstrap
{
    public static function ensureAppKey(): void
    {
        $current = (string) config('app.key', '');
        if ($current !== '' && str_starts_with($current, 'base64:')) {
            return;
        }

        $keyPath = base_path('.docker/app.key');
        if (! is_file($keyPath)) {
            if (! is_dir(dirname($keyPath))) {
                mkdir(dirname($keyPath), 0777, true);
            }
            file_put_contents($keyPath, 'base64:'.base64_encode(random_bytes(32)));
        }

        $key = trim((string) file_get_contents($keyPath));
        if ($key === '') {
            $key = 'base64:'.base64_encode(random_bytes(32));
            file_put_contents($keyPath, $key);
        }

        self::upsertEnvValue('APP_KEY', $key);
        config(['app.key' => $key]);
    }

    public static function ensureUsersSchemaReady(): void
    {
        try {
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                return;
            }
        } catch (\Throwable) {
            // segue para migrate
        }

        Artisan::call('migrate', ['--force' => true]);
    }

    public static function upsertEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            copy(base_path('.env.example'), $envPath);
        }

        $content = (string) file_get_contents($envPath);
        $needsQuotes = (bool) preg_match('/\s|#|"|\'/', $value);
        $line = $key.'='.($needsQuotes ? ('"'.str_replace('"', '\\"', $value).'"') : $value);
        $pattern = '/^\s*'.preg_quote($key, '/').'\s*=.*$/m';

        if (preg_match($pattern, $content)) {
            $content = (string) preg_replace($pattern, $line, $content);
        } else {
            $content = rtrim($content, "\r\n")."\n".$line."\n";
        }

        file_put_contents($envPath, str_replace("\r\n", "\n", $content));
    }

    public static function friendlyDatabaseError(\Throwable $e): ?string
    {
        if (! $e instanceof QueryException) {
            return null;
        }

        $message = $e->getMessage();
        if (str_contains($message, 'does not exist') || str_contains($message, '42P01')) {
            return 'Banco ainda não migrado. Aguarde o container terminar o boot e tente novamente.';
        }

        return null;
    }
}
