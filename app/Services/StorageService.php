<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class StorageService
{
    private ?int $tenantId = null;

    private ?Filesystem $disk = null;

    private bool $isLocal = true;

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
     * Get the active storage disk for the current tenant.
     */
    public function disk(): Filesystem
    {
        if ($this->disk !== null) {
            return $this->disk;
        }

        $cloudMode = (bool) config('getfy.cloud_mode', false);
        $r2Env = $this->r2EnvConfig();

        $provider = Setting::get('storage_provider', null, $this->tenantId);
        if ($provider === null || $provider === '') {
            $provider = ($cloudMode && $r2Env['configured']) ? 'r2' : 'local';
        }

        if ($provider === 'local' || empty($provider)) {
            $this->disk = Storage::disk('public');
            $this->isLocal = true;

            return $this->disk;
        }

        $key = Setting::get('storage_s3_key', '', $this->tenantId);
        $secretRaw = Setting::get('storage_s3_secret', '', $this->tenantId);
        $secret = '';
        if ($secretRaw) {
            try {
                $secret = Crypt::decryptString($secretRaw);
            } catch (\Throwable) {
                $secret = '';
            }
        }
        $bucket = Setting::get('storage_s3_bucket', '', $this->tenantId);
        $region = Setting::get('storage_s3_region', 'us-east-1', $this->tenantId);
        $endpoint = Setting::get('storage_s3_endpoint', '', $this->tenantId);
        $url = Setting::get('storage_s3_url', '', $this->tenantId);

        $useEnvR2 = $cloudMode
            && $provider === 'r2'
            && $r2Env['configured']
            && trim((string) $key) === ''
            && trim((string) $bucket) === ''
            && trim((string) $endpoint) === ''
            && trim((string) $url) === ''
            && trim((string) $secretRaw) === '';

        if ($useEnvR2) {
            $key = $r2Env['key'];
            $secret = $r2Env['secret'];
            $bucket = $r2Env['bucket'];
            $endpoint = $r2Env['endpoint'];
            $url = $r2Env['url'];
            $region = $r2Env['region'];
        }

        if (empty($key) || empty($secret) || empty($bucket)) {
            $this->disk = Storage::disk('public');
            $this->isLocal = true;

            return $this->disk;
        }

        $isR2 = $provider === 'r2' || ($endpoint && str_contains($endpoint, 'r2.cloudflarestorage.com'));
        $regionForConfig = $isR2 ? 'auto' : ($region ?: 'us-east-1');

        $config = [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secret,
            'region' => $regionForConfig,
            'bucket' => $bucket,
            'throw' => false,
            'report' => false,
        ];

        if ($endpoint) {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = str_contains($endpoint, 'r2.cloudflarestorage.com')
                || str_contains($endpoint, 'wasabisys.com')
                || str_contains($endpoint, 'digitaloceanspaces.com');
        }

        if ($url) {
            $config['url'] = rtrim($url, '/');
        }

        $this->disk = Storage::build($config);
        $this->isLocal = false;

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

        return $this->disk()->putFileAs($directory, $file, $name);
    }

    /**
     * Store file with putFileAs.
     */
    public function putFileAs(string $directory, UploadedFile $file, string $name): string
    {
        return $this->disk()->putFileAs($directory, $file, $name);
    }

    /**
     * Get the public URL for a stored file (path relativo no bucket/disco).
     */
    public function url(string $path): string
    {
        return $this->resolvePublicUrl($path);
    }

    /**
     * Converte valor salvo no banco (path, /storage/... ou URL local antiga) na URL pública atual (local ou R2).
     */
    public function resolvePublicUrl(?string $stored): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }

        $stored = trim($stored);
        $normalizer = new StorageUrlNormalizer;

        if (preg_match('#^https?://#i', $stored)) {
            if ($normalizer->isLocalStorageUrl($stored)) {
                $stored = $normalizer->toRelativePath($stored);
            } else {
                return $stored;
            }
        } elseif (str_starts_with($stored, '/storage/')) {
            $stored = ltrim(substr($stored, strlen('/storage/')), '/');
        }

        $this->disk();

        if ($this->isLocal) {
            return url('/storage/' . ltrim($stored, '/'));
        }

        return $this->disk->url($stored);
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

        if (preg_match('#^https?://#i', $value)) {
            if ($normalizer->isLocalStorageUrl($value)) {
                return $normalizer->toRelativePath($value);
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
            return (new StorageUrlNormalizer)->isLocalStorageUrl($value);
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
        if (empty($path)) {
            return false;
        }

        return $this->disk()->delete($path);
    }

    /**
     * Check if a file exists.
     */
    public function exists(string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        return $this->disk()->exists($path);
    }
}
