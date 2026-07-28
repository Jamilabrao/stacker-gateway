<?php

namespace App\Services;

use App\Support\DockerEnvBootstrap;
use App\Support\DockerSetupState;
use App\Support\PublicAppUrl;
use InvalidArgumentException;

/**
 * Alinha a URL pública da instalação em .env, Docker e runtime
 * (APP_URL, GETFY_WEBHOOK_PUBLIC_URL, GETFY_APP_URL, .docker/app.url).
 */
class InstallationPublicUrlService
{
    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    public function basePath(): string
    {
        return $this->basePath ?? base_path();
    }

    /**
     * @return array{
     *     app_url: string,
     *     webhook_public_url: string,
     *     resolved_public_url: string,
     *     host: string,
     *     docker_mode: bool,
     *     urls_diverged: bool,
     *     agent_restart_hint: bool
     * }
     */
    public function snapshot(): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $webhookUrl = rtrim((string) config('getfy.webhook_public_url', ''), '/');
        $resolved = rtrim(PublicAppUrl::base(), '/');
        $host = (string) (parse_url($resolved, PHP_URL_HOST) ?: parse_url($appUrl, PHP_URL_HOST) ?: '');

        $normalizedApp = $this->tryNormalize($appUrl);
        $normalizedWebhook = $webhookUrl !== '' ? $this->tryNormalize($webhookUrl) : null;
        $diverged = $normalizedWebhook !== null
            && $normalizedApp !== null
            && $normalizedWebhook !== $normalizedApp;

        return [
            'app_url' => $appUrl,
            'webhook_public_url' => $webhookUrl,
            'resolved_public_url' => $resolved,
            'host' => $host,
            'docker_mode' => DockerSetupState::isDocker(),
            'urls_diverged' => $diverged,
            'agent_restart_hint' => DockerSetupState::isDocker() || is_file($this->basePath().DIRECTORY_SEPARATOR.'.docker'.DIRECTORY_SEPARATOR.'stack.env'),
        ];
    }

    /**
     * @return array{
     *     url: string,
     *     host: string,
     *     docker_mode: bool,
     *     agent_restart_hint: bool
     * }
     */
    public function apply(string $input): array
    {
        $url = $this->normalize($input);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: 'https'));

        $this->persistEnvKeys($url, $scheme === 'https');
        $this->persistDockerAppUrl($url);
        $this->persistStackEnv($url);
        $this->persistCaddyDomain($host);
        $this->applyRuntime($url, $scheme === 'https');

        return [
            'url' => $url,
            'host' => $host,
            'docker_mode' => DockerSetupState::isDocker(),
            'agent_restart_hint' => DockerSetupState::isDocker()
                || is_file($this->basePath().DIRECTORY_SEPARATOR.'.docker'.DIRECTORY_SEPARATOR.'stack.env'),
        ];
    }

    public function normalize(string $input): string
    {
        $value = trim($input);
        $value = str_replace(["\r", "\n", "\t"], '', $value);
        $value = trim($value, " \t\n\r\0\x0B`'\"");
        $value = preg_replace('#\s+#', '', $value) ?: '';

        if ($value === '') {
            throw new InvalidArgumentException('Informe a URL pública da instalação.');
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new InvalidArgumentException('URL inválida. Use o formato https://app.seudominio.com');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('A URL deve usar http ou https.');
        }

        $host = strtolower((string) $parts['host']);
        $host = rtrim($host, '.');

        if ($host === '') {
            throw new InvalidArgumentException('Host inválido na URL.');
        }

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new InvalidArgumentException('Host inválido na URL.');
            }
        }

        $url = $scheme.'://'.$host;
        if (! empty($parts['port'])) {
            $url .= ':'.(int) $parts['port'];
        }

        if (PublicAppUrl::isLocalHost($url) && ! $this->allowsLocalHost()) {
            throw new InvalidArgumentException('Não use localhost como URL pública em produção.');
        }

        return $url;
    }

    private function tryNormalize(string $input): ?string
    {
        try {
            return $this->normalize($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function allowsLocalHost(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function persistEnvKeys(string $url, bool $secureCookie): void
    {
        $envPath = $this->basePath().DIRECTORY_SEPARATOR.'.env';
        if (! is_file($envPath) && is_file($this->basePath().DIRECTORY_SEPARATOR.'.env.example')) {
            copy($this->basePath().DIRECTORY_SEPARATOR.'.env.example', $envPath);
        }

        if ($this->basePath === null) {
            DockerEnvBootstrap::upsertEnvValue('APP_URL', $url);
            DockerEnvBootstrap::upsertEnvValue('GETFY_WEBHOOK_PUBLIC_URL', $url);
            DockerEnvBootstrap::upsertEnvValue('GETFY_APP_URL', $url);
            DockerEnvBootstrap::upsertEnvValue('SESSION_SECURE_COOKIE', $secureCookie ? 'true' : 'false');

            return;
        }

        $this->upsertEnvFile($envPath, [
            'APP_URL' => $url,
            'GETFY_WEBHOOK_PUBLIC_URL' => $url,
            'GETFY_APP_URL' => $url,
            'SESSION_SECURE_COOKIE' => $secureCookie ? 'true' : 'false',
        ]);
    }

    private function persistDockerAppUrl(string $url): void
    {
        $dockerDir = $this->basePath().DIRECTORY_SEPARATOR.'.docker';
        if (! is_dir($dockerDir)) {
            @mkdir($dockerDir, 0777, true);
        }
        if (! is_dir($dockerDir)) {
            return;
        }

        file_put_contents($dockerDir.DIRECTORY_SEPARATOR.'app.url', $url);
    }

    private function persistStackEnv(string $url): void
    {
        $dockerDir = $this->basePath().DIRECTORY_SEPARATOR.'.docker';
        if (! is_dir($dockerDir)) {
            @mkdir($dockerDir, 0777, true);
        }
        if (! is_dir($dockerDir)) {
            return;
        }

        $stackEnv = $dockerDir.DIRECTORY_SEPARATOR.'stack.env';
        $this->upsertEnvFile($stackEnv, [
            'GETFY_APP_URL' => $url,
            'GETFY_WEBHOOK_PUBLIC_URL' => $url,
            'APP_URL' => $url,
        ]);
    }

    private function persistCaddyDomain(string $host): void
    {
        if ($host === '' || PublicAppUrl::isLocalHost('https://'.$host)) {
            return;
        }

        $dockerDir = $this->basePath().DIRECTORY_SEPARATOR.'.docker';
        if (! is_dir($dockerDir)) {
            return;
        }

        // Só reescreve se já existir bloco de domínio (instalação Docker com Caddy).
        $caddyFile = $dockerDir.DIRECTORY_SEPARATOR.'Caddyfile.domains';
        if (! is_file($caddyFile) && ! DockerSetupState::isDocker()) {
            return;
        }

        $cert = $dockerDir.DIRECTORY_SEPARATOR.'certs'.DIRECTORY_SEPARATOR.'origin.pem';
        $key = $dockerDir.DIRECTORY_SEPARATOR.'certs'.DIRECTORY_SEPARATOR.'origin-key.pem';
        if (is_file($cert) && is_file($key)) {
            $tlsLine = "\ttls /etc/getfy/certs/origin.pem /etc/getfy/certs/origin-key.pem\n";
        } else {
            $tlsLine = "\ttls internal\n";
        }

        file_put_contents(
            $caddyFile,
            $host." {\n".$tlsLine."\treverse_proxy app:80\n}\n"
        );
    }

    private function applyRuntime(string $url, bool $secureCookie): void
    {
        config([
            'app.url' => $url,
            'getfy.webhook_public_url' => $url,
            'session.secure' => $secureCookie,
            'filesystems.disks.public.url' => $url.'/storage',
        ]);

        PublicAppUrl::forceRoot($url);

        $cached = $this->basePath().DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        if (is_file($cached)) {
            try {
                \Illuminate\Support\Facades\Artisan::call('config:clear');
            } catch (\Throwable) {
                // Ambiente sem artisan completo (testes) — config runtime já foi atualizado.
            }
        }
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function upsertEnvFile(string $envPath, array $vars): void
    {
        if (! is_file($envPath)) {
            $content = '';
        } else {
            $content = (string) file_get_contents($envPath);
        }

        foreach ($vars as $key => $value) {
            $needsQuotes = (bool) preg_match('/\s|#|"|\'/', $value);
            $line = $key.'='.($needsQuotes ? ('"'.str_replace('"', '\\"', $value).'"') : $value);
            $pattern = '/^\s*'.preg_quote($key, '/').'\s*=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace($pattern, $line, $content);
            } else {
                $content = rtrim($content, "\r\n")."\n".$line."\n";
            }
        }

        file_put_contents($envPath, str_replace("\r\n", "\n", $content));
    }
}
