<?php

namespace App\Services;

use App\Support\RemoteStorage;

/**
 * Testa e monta cliente S3/R2 sem depender do fluxo completo do Flysystem no teste de conexão.
 */
class StorageConnectionTester
{
    public static function awsSdkAvailable(): bool
    {
        return class_exists(\Aws\S3\S3Client::class);
    }

    public static function ensureAwsSdk(): void
    {
        if (! self::awsSdkAvailable()) {
            throw new \RuntimeException(
                'Pacote aws/aws-sdk-php não está instalado no servidor. No container/app rode: composer install --no-dev'
            );
        }
    }

    /**
     * @param  array{provider: string, key: string, secret: string, bucket: string, region: string, endpoint: string, url?: string}  $creds
     */
    public static function makeClient(array $creds): \Aws\S3\S3Client
    {
        self::ensureAwsSdk();
        $endpoint = trim((string) ($creds['endpoint'] ?? ''));
        $provider = (string) ($creds['provider'] ?? 's3');
        $isR2 = $provider === 'r2' || RemoteStorage::isR2ApiEndpoint($endpoint);
        $region = trim((string) ($creds['region'] ?? ''));
        if ($region === '') {
            $region = $isR2 ? 'auto' : 'us-east-1';
        }

        $config = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => (string) ($creds['key'] ?? ''),
                'secret' => (string) ($creds['secret'] ?? ''),
            ],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];

        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = RemoteStorage::isR2ApiEndpoint($endpoint)
                || str_contains($endpoint, 'wasabisys.com')
                || str_contains($endpoint, 'digitaloceanspaces.com');
        }

        return new \Aws\S3\S3Client($config);
    }

    /**
     * @param  array{provider: string, key: string, secret: string, bucket: string, region: string, endpoint: string}  $creds
     * @return array{sample_public_url: string|null}
     */
    public static function test(array $creds, string $publicUrl = ''): array
    {
        $bucket = trim((string) ($creds['bucket'] ?? ''));
        if ($bucket === '') {
            throw new \InvalidArgumentException('Bucket é obrigatório.');
        }

        $endpoint = trim((string) ($creds['endpoint'] ?? ''));
        if (($creds['provider'] ?? '') === 'r2' && $endpoint === '') {
            throw new \InvalidArgumentException('Endpoint R2 é obrigatório (https://<account>.r2.cloudflarestorage.com).');
        }

        $client = self::makeClient($creds);

        $client->listObjectsV2([
            'Bucket' => $bucket,
            'MaxKeys' => 1,
        ]);

        $sampleUrl = null;
        $publicUrl = RemoteStorage::normalizePublicBaseUrl($publicUrl);
        if ($publicUrl !== '') {
            $probeKey = '.getfy-storage-test-'.uniqid('', true).'.txt';
            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $probeKey,
                'Body' => 'ok',
            ]);
            $sampleUrl = RemoteStorage::buildPublicUrl($publicUrl, $probeKey);
            $client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $probeKey,
            ]);
        }

        return ['sample_public_url' => $sampleUrl];
    }

    public static function friendlyMessage(\Throwable $e): string
    {
        if (class_exists(RemoteStorage::class)) {
            return RemoteStorage::friendlyErrorMessage($e);
        }

        $msg = $e->getMessage();

        return $msg !== '' ? $msg : 'Erro ao conectar ao storage.';
    }
}
