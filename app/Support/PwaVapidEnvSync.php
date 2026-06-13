<?php

namespace App\Support;

/**
 * Sincroniza PWA_VAPID_* do .env para .docker/pwa_vapid.env (volume compartilhado app + queue).
 */
final class PwaVapidEnvSync
{
    public static function syncFromDotEnv(?string $envPath = null): void
    {
        $envPath = $envPath ?? base_path('.env');
        $sharedFile = base_path('.docker/pwa_vapid.env');

        if (! is_file($envPath)) {
            return;
        }

        $env = str_replace("\r\n", "\n", (string) file_get_contents($envPath));
        $out = '';

        foreach (['PWA_VAPID_PUBLIC', 'PWA_VAPID_PRIVATE'] as $key) {
            if (! preg_match('/^\s*'.$key.'\s*=\s*(.+)\s*$/mi', $env, $m)) {
                continue;
            }
            $v = trim((string) ($m[1] ?? ''));
            $v = trim($v, " \t\n\r\0\x0B\"'`");
            if ($v === '') {
                continue;
            }
            $out .= $key.'="'.str_replace('"', '\\"', $v)."\"\n";
        }

        if ($out === '') {
            return;
        }

        @mkdir(dirname($sharedFile), 0777, true);
        file_put_contents($sharedFile, $out);
    }

    /**
     * @return array{publicKey: string, privateKey: string}|null
     */
    public static function readKeyPairFromDotEnv(?string $envPath = null): ?array
    {
        $envPath = $envPath ?? base_path('.env');
        if (! is_file($envPath)) {
            return null;
        }

        $env = str_replace("\r\n", "\n", (string) file_get_contents($envPath));
        $read = static function (string $key) use ($env): ?string {
            if (! preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.+)\s*$/mi', $env, $m)) {
                return null;
            }
            $v = trim((string) ($m[1] ?? ''));
            $v = trim($v, " \t\n\r\0\x0B\"'`");

            return $v === '' ? null : $v;
        };

        $pub = VapidEnvKeys::normalize($read('PWA_VAPID_PUBLIC'));
        $priv = VapidEnvKeys::normalize($read('PWA_VAPID_PRIVATE'));
        if ($pub === null || $priv === null) {
            return null;
        }

        return ['publicKey' => $pub, 'privateKey' => $priv];
    }

    public static function writeKeysToDotEnv(string $publicKey, string $privateKey, ?string $envPath = null): void
    {
        $envPath = $envPath ?? base_path('.env');
        if (! is_file($envPath)) {
            return;
        }

        $content = (string) file_get_contents($envPath);
        $publicEscaped = '"'.str_replace('"', '\\"', $publicKey).'"';
        $privateEscaped = '"'.str_replace('"', '\\"', $privateKey).'"';

        foreach ([
            'PWA_VAPID_PUBLIC' => $publicEscaped,
            'PWA_VAPID_PRIVATE' => $privateEscaped,
        ] as $key => $escaped) {
            $pattern = '/^\s*'.preg_quote($key, '/').'\s*=.*$/m';
            $line = $key.'='.$escaped;
            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace($pattern, $line, $content);
            } else {
                $content = rtrim($content, "\r\n")."\n".$line."\n";
            }
        }

        file_put_contents($envPath, $content);
        self::syncFromDotEnv($envPath);
    }
}
