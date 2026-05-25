<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\RemoteStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageService
{
    private ?int $tenantId = null;

    private ?Filesystem $disk = null;

    private bool $isLocal = true;

    /** @var array{provider: string, key: string, secret: string, bucket: string, region: string, endpoint: string, url: string}|null */
    private ?array $remoteCredentials = null;

    public function __construct(?int $tenantId = null)
    {
        $this->tenantId = $tenantId ?? auth()->user()?->tenant_id;
    }

    /**
     * @return array{configured: bool, key: string, secret: string, bucket: string, endpoint: string, url: string, region: string}
     */
    private function r2EnvConfig(): array
    {
        $key = (string) env('R2_ACCESS_KEY_ID', '');
        $secret = (string) env('R2_SECRET_ACCESS_KEY', '');
        $bucket = (string) env('R2_BUCKET', '');
        $endpoint = (string) env('R2_ENDPOINT', '');
        $url = (string) env('R2_PUBLIC_URL', '');
        $region = (string) env('R2_REGION', 'auto');

        $configured = $key !== '' && $secret !== '' && $bucket !== '' && $endpoint !== '';

        return [
            'configured' => $configured,
            'key' => $key,
            'secret' => $secret,
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'url' => $url,
            'region' => $region ?: 'auto',
        ];
    }

    /**
     * @return array{provider: string, key: string, secret: string, bucket: string, region: string, endpoint: string, url: string}
     */
    public function resolveRemoteCredentials(): array
    {
        if ($this->remoteCredentials !== null) {
            return $this->remoteCredentials;
        }

        $cloudMode = (bool) config('getfy.cloud_mode', false);
        $r2Env = $this->r2EnvConfig();

        $provider = Setting::get('storage_provider', null, $this->tenantId);
        if ($provider === null || $provider === '') {
            $provider = ($cloudMode && $r2Env['configured']) ? 'r2' : 'local';
        }

        if ($provider === 'local' || $provider === '') {
            $this->remoteCredentials = [
                'provider' => 'local',
                'key' => '',
                'secret' => '',
                'bucket' => '',
                'region' => '',
                'endpoint' => '',
                'url' => '',
            ];

            return $this->remoteCredentials;
        }

        $key = (string) Setting::get('storage_s3_key', '', $this->tenantId);
        $secretRaw = Setting::get('storage_s3_secret', '', $this->tenantId);
        $secret = '';
        if ($secretRaw) {
            try {
                $secret = Crypt::decryptString($secretRaw);
            } catch (\Throwable) {
                $secret = '';
            }
        }
        $bucket = (string) Setting::get('storage_s3_bucket', '', $this->tenantId);
        $region = (string) Setting::get('storage_s3_region', 'us-east-1', $this->tenantId);
        $endpoint = (string) Setting::get('storage_s3_endpoint', '', $this->tenantId);
        $url = (string) Setting::get('storage_s3_url', '', $this->tenantId);

        $useEnvR2 = $cloudMode
            && $provider === 'r2'
            && $r2Env['configured']
            && trim($key) === ''
            && trim($bucket) === ''
            && trim($endpoint) === ''
            && trim($url) === ''
            && trim((string) $secretRaw) === '';

        if ($useEnvR2) {
            $key = $r2Env['key'];
            $secret = $r2Env['secret'];
            $bucket = $r2Env['bucket'];
            $endpoint = $r2Env['endpoint'];
            $url = $r2Env['url'];
            $region = $r2Env['region'];
        }

        $this->remoteCredentials = [
            'provider' => (string) $provider,
            'key' => $key,
            'secret' => $secret,
            'bucket' => $bucket,
            'region' => $region,
            'endpoint' => $endpoint,
            'url' => RemoteStorage::normalizePublicBaseUrl($url),
        ];

        return $this->remoteCredentials;
    }

    /**
     * URL pública base (CDN / pub-*.r2.dev). Vazio se não configurado.
     */
    public function publicBaseUrl(): string
    {
        $creds = $this->resolveRemoteCredentials();
        if (($creds['provider'] ?? 'local') === 'local') {
            return '';
        }

        return RemoteStorage::resolvePublicBaseUrlForProvider(
            $creds['provider'],
            $creds['url'],
            $this->r2EnvConfig()
        );
    }

    /**
     * Get the active storage disk for the current tenant.
     */
    public function disk(): Filesystem
    {
        if ($this->disk !== null) {
            return $this->disk;
        }

        $creds = $this->resolveRemoteCredentials();

        if (($creds['provider'] ?? 'local') === 'local'
            || $creds['key'] === ''
            || $creds['secret'] === ''
            || $creds['bucket'] === '') {
            $this->disk = Storage::disk('public');
            $this->isLocal = true;

            return $this->disk;
        }

        try {
            $diskConfig = RemoteStorage::buildS3DiskConfig($creds);
            $this->disk = Storage::build($diskConfig);
            $this->isLocal = false;
        } catch (\Throwable $e) {
            Log::warning('storage.disk_build_failed', [
                'provider' => $creds['provider'] ?? null,
                'message' => $e->getMessage(),
            ]);
            try {
                $this->disk = Storage::build([
                    'driver' => 's3',
                    'key' => $creds['key'],
                    'secret' => $creds['secret'],
                    'region' => ($creds['provider'] ?? '') === 'r2' ? 'auto' : ($creds['region'] ?: 'us-east-1'),
                    'bucket' => $creds['bucket'],
                    'endpoint' => $creds['endpoint'] ?? null,
                    'url' => $creds['url'] ?? null,
                    'use_path_style_endpoint' => RemoteStorage::isR2ApiEndpoint($creds['endpoint'] ?? ''),
                    'visibility' => ($creds['provider'] ?? '') === 'r2' ? 'private' : 'public',
                    'retain_visibility' => false,
                    'throw' => false,
                    'report' => false,
                    'request_checksum_calculation' => 'when_required',
                    'response_checksum_validation' => 'when_required',
                ]);
                $this->isLocal = false;
            } catch (\Throwable) {
                $this->disk = Storage::disk('public');
                $this->isLocal = true;
            }
        }

        return $this->disk;
    }

    /**
     * Whether the current disk is local (public) or remote (S3/R2).
     */
    public function isLocal(): bool
    {
        $this->disk();

        return $this->isLocal;
    }

    /**
     * Store an uploaded file and return the path.
     */
    public function putFile(string $directory, UploadedFile $file, ?string $name = null): string
    {
        $name = $name ?? $file->hashName();

        return $this->putFileAs($directory, $file, $name);
    }

    /**
     * Store file with putFileAs.
     */
    public function putFileAs(string $directory, UploadedFile $file, string $name): string
    {
        $creds = $this->resolveRemoteCredentials();
        $provider = (string) ($creds['provider'] ?? 'local');

        if (RemoteStorage::requiresPublicBaseUrl($provider) && $this->publicBaseUrl() === '') {
            throw new \RuntimeException(
                'Configure a URL pública do R2 (ex.: https://media.seudominio.com) em Configurações → Storage antes de enviar imagens.'
            );
        }

        try {
            $stored = $this->disk()->putFileAs(
                $directory,
                $file,
                $name,
                RemoteStorage::uploadOptionsForProvider($provider)
            );
        } catch (\Throwable $e) {
            Log::warning('storage.put_file_failed', [
                'provider' => $provider,
                'directory' => $directory,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(RemoteStorage::friendlyErrorMessage($e), 0, $e);
        }

        if ($stored === false || $stored === '') {
            throw new \RuntimeException(
                'Não foi possível enviar o arquivo. Verifique credenciais do storage e, no R2, o acesso público ao bucket.'
            );
        }

        return $stored;
    }

    /**
     * Get the public URL for a stored file (path relativo no bucket/disco).
     */
    public function url(string $path): string
    {
        return $this->resolvePublicUrl($path);
    }

    /**
     * Converte valor salvo no banco (path, /storage/... ou URL) na URL pública atual (local ou CDN/R2).
     */
    public function resolvePublicUrl(?string $stored): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }

        try {
            return $this->finalizePublicUrl($this->resolvePublicUrlUnsafe(trim($stored)));
        } catch (\Throwable $e) {
            Log::warning('storage.resolve_public_url_failed', [
                'message' => $e->getMessage(),
            ]);

            return $this->finalizePublicUrl($this->fallbackPublicUrl(trim($stored)));
        }
    }

    /**
     * Evita src relativo no HTML (ex.: avatars/foto.png → 404 em /plataforma/meu-perfil).
     */
    private function finalizePublicUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return url($url);
        }

        return url('/storage/'.ltrim($url, '/'));
    }

    private function resolvePublicUrlUnsafe(string $stored): string
    {
        $normalizer = new StorageUrlNormalizer;
        $creds = $this->resolveRemoteCredentials();
        $bucket = $creds['bucket'] ?? '';

        $base = '';
        $this->disk();
        if (! $this->isLocal) {
            $base = $this->publicBaseUrl();
        }

        if (preg_match('#^https?://#i', $stored)) {
            if ($base !== '') {
                $repaired = RemoteStorage::repairEmbeddedPublicHostUrl($stored, $base);
                if ($repaired !== null) {
                    return $repaired;
                }
            }
            if ($normalizer->isLocalStorageUrl($stored)) {
                $stored = $normalizer->toRelativePath($stored);
            } elseif (RemoteStorage::isLikelyNonPublicUrl($stored)) {
                $key = RemoteStorage::extractObjectKeyFromUrl($stored, $bucket !== '' ? $bucket : null);
                $stored = $key ?? $stored;
            } else {
                return $stored;
            }
        } elseif (str_starts_with($stored, '/storage/')) {
            $stored = ltrim(substr($stored, strlen('/storage/')), '/');
        } elseif (preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}/#i', $stored)) {
            return RemoteStorage::ensureAbsoluteUrl($stored);
        }

        if ($this->isLocal) {
            return url('/storage/'.ltrim($stored, '/'));
        }

        if ($base !== '') {
            return RemoteStorage::buildPublicUrl($base, $stored);
        }

        $adapterUrl = $this->disk->url($stored);
        if (RemoteStorage::isLikelyNonPublicUrl($adapterUrl)) {
            return url('/storage/'.ltrim($stored, '/'));
        }

        return $adapterUrl;
    }

    private function fallbackPublicUrl(string $stored): string
    {
        if (preg_match('#^https?://#i', $stored)) {
            return $stored;
        }

        if (str_starts_with($stored, '/storage/')) {
            return url($stored);
        }

        return url('/storage/'.ltrim($stored, '/'));
    }

    /**
     * Normaliza URL/caminho recebido do front para gravar no banco (preferir path relativo).
     */
    public function toStoragePath(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $normalizer = new StorageUrlNormalizer;
        $creds = $this->resolveRemoteCredentials();
        $bucket = $creds['bucket'] ?? '';

        if (preg_match('#^https?://#i', $value)) {
            if ($normalizer->isLocalStorageUrl($value)) {
                return $normalizer->toRelativePath($value);
            }

            if (RemoteStorage::isLikelyNonPublicUrl($value)) {
                return RemoteStorage::extractObjectKeyFromUrl($value, $bucket !== '' ? $bucket : null);
            }

            $key = RemoteStorage::extractObjectKeyFromUrl($value, $bucket !== '' ? $bucket : null);
            if ($key !== null && $key !== '') {
                return $key;
            }

            return $value;
        }

        if (str_starts_with($value, '/storage/')) {
            return ltrim(substr($value, strlen('/storage/')), '/');
        }

        return ltrim($value, '/');
    }

    /**
     * Resolve URLs de mídia dentro de member_area_config (logos, hero, login, etc.).
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    public function resolveMediaUrlsInConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        return $this->resolveMediaUrlsInArray($config);
    }

    /**
     * @param  array<string, mixed>  $arr
     * @return array<string, mixed>
     */
    private function resolveMediaUrlsInArray(array $arr): array
    {
        foreach ($arr as $key => $value) {
            if (is_string($value) && $this->shouldResolveConfigMediaString($value)) {
                $arr[$key] = $this->resolvePublicUrl($value);
            } elseif (is_array($value)) {
                $arr[$key] = $this->resolveMediaUrlsInArray($value);
            }
        }

        return $arr;
    }

    private function shouldResolveConfigMediaString(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $value)) {
            if ((new StorageUrlNormalizer)->isLocalStorageUrl($value)) {
                return true;
            }

            return RemoteStorage::isLikelyNonPublicUrl($value);
        }

        if (str_starts_with($value, '/storage/')) {
            return true;
        }

        $prefixes = [
            'member-area/',
            'member-area-gamification/',
            'products/',
            'checkout/',
            'branding/',
            'email-templates/',
            'dashboard-banners/',
            'platform/',
        ];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a file.
     */
    public function delete(string $path): bool
    {
        $path = $this->normalizeStoragePath($path);
        if ($path === '') {
            return false;
        }

        try {
            return $this->disk()->delete($path);
        } catch (\Throwable $e) {
            Log::warning('storage.delete_failed', ['path' => $path, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Check if a file exists.
     */
    public function exists(string $path): bool
    {
        $path = $this->normalizeStoragePath($path);
        if ($path === '') {
            return false;
        }

        try {
            return $this->disk()->exists($path);
        } catch (\Throwable $e) {
            Log::warning('storage.exists_failed', ['path' => $path, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Converte URL completa ou /storage/... no path relativo do bucket/disco.
     */
    public function normalizeStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            $bucket = $this->resolveRemoteCredentials()['bucket'] ?? '';
            $key = RemoteStorage::extractObjectKeyFromUrl(
                $path,
                is_string($bucket) && $bucket !== '' ? $bucket : null
            );

            return $key ?? $path;
        }

        if (str_starts_with($path, '/storage/')) {
            return ltrim(substr($path, strlen('/storage/')), '/');
        }

        return ltrim($path, '/');
    }
}
