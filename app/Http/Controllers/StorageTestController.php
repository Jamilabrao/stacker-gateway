<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Teste de storage isolado (sem RemoteStorage) para evitar 500 por autoload/sintaxe em produção.
 */
class StorageTestController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            return $this->runTest($request);
        } catch (\Throwable $e) {
            Log::warning('storage.test_failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->friendlyAwsMessage($e),
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ], 422);
        }
    }

    private function runTest(Request $request): JsonResponse
    {
        if (! class_exists(\Aws\S3\S3Client::class)) {
            return $this->jsonFail('Pacote aws/aws-sdk-php não está instalado. Execute composer install --no-dev no servidor.');
        }

        $provider = (string) $request->input('storage_provider', 'local');

        if ($provider === 'local') {
            return response()->json([
                'success' => true,
                'message' => 'Storage local está ativo.',
            ]);
        }

        $cloudMode = (bool) config('getfy.cloud_mode', false);
        $r2EnvKey = (string) env('R2_ACCESS_KEY_ID', '');
        $r2EnvSecret = (string) env('R2_SECRET_ACCESS_KEY', '');
        $r2EnvBucket = (string) env('R2_BUCKET', '');
        $r2EnvEndpoint = (string) env('R2_ENDPOINT', '');
        $r2EnvPublicUrl = (string) env('R2_PUBLIC_URL', '');
        $r2EnvConfigured = $r2EnvKey !== '' && $r2EnvSecret !== '' && $r2EnvBucket !== '' && $r2EnvEndpoint !== '';

        $keyInput = (string) $request->input('storage_s3_key', '');
        $bucketInput = (string) $request->input('storage_s3_bucket', '');
        $endpointInput = (string) $request->input('storage_s3_endpoint', '');
        $publicUrlInput = $this->normalizePublicBaseUrl((string) $request->input('storage_s3_url', ''));

        $useEnvR2 = $cloudMode
            && $provider === 'r2'
            && $r2EnvConfigured
            && trim($keyInput) === ''
            && trim($bucketInput) === ''
            && trim($endpointInput) === ''
            && $publicUrlInput === ''
            && trim((string) $request->input('storage_s3_secret', '')) === '';

        if (! $useEnvR2) {
            $validator = Validator::make($request->all(), [
                'storage_provider' => ['required', 'string', 'in:s3,wasabi,r2'],
                'storage_s3_key' => ['required', 'string', 'max:255'],
                'storage_s3_secret' => ['nullable', 'string', 'max:512'],
                'storage_s3_bucket' => ['required', 'string', 'max:255'],
                'storage_s3_region' => ['nullable', 'string', 'max:64'],
                'storage_s3_endpoint' => ['nullable', 'string', 'max:512'],
                'storage_s3_url' => ['nullable', 'string', 'max:512'],
            ], [
                'storage_provider.required' => 'Selecione um provedor de storage (S3, Wasabi ou R2).',
                'storage_provider.in' => 'Provedor inválido. Use S3, Wasabi ou R2.',
                'storage_s3_key.required' => 'O campo Access Key é obrigatório.',
                'storage_s3_bucket.required' => 'O campo Bucket é obrigatório.',
            ]);

            if ($validator->fails()) {
                return $this->jsonFail($validator->errors()->first() ?: 'Dados inválidos.');
            }
        }

        $key = $useEnvR2 ? $r2EnvKey : (string) $request->input('storage_s3_key');
        $secret = $useEnvR2 ? $r2EnvSecret : $request->input('storage_s3_secret');
        if ($secret === null || $secret === '') {
            try {
                $secretRaw = Setting::get('storage_s3_secret', '', null);
                if ($secretRaw !== '') {
                    $secret = Crypt::decryptString($secretRaw);
                }
            } catch (\Throwable) {
                $secret = '';
            }
        }
        if ($secret === null || $secret === '') {
            return $this->jsonFail('O campo Secret Key é obrigatório. Preencha e salve as configurações uma vez.');
        }

        $bucket = $useEnvR2 ? $r2EnvBucket : (string) $request->input('storage_s3_bucket');
        $region = $provider === 'r2' ? 'auto' : (string) $request->input('storage_s3_region', 'us-east-1');
        $endpoint = $useEnvR2 ? $r2EnvEndpoint : (string) $request->input('storage_s3_endpoint', '');
        $publicUrl = $useEnvR2
            ? $this->normalizePublicBaseUrl($r2EnvPublicUrl)
            : $publicUrlInput;

        if ($provider === 'r2' && $publicUrl === '') {
            return $this->jsonFail(
                'Para Cloudflare R2, informe a URL pública (ex.: https://media.seudominio.com).'
            );
        }

        if ($publicUrl !== '' && $this->isR2ApiEndpoint($publicUrl)) {
            return $this->jsonFail('A URL pública não pode ser o endpoint da API (*.r2.cloudflarestorage.com).');
        }

        if ($provider === 'r2' && trim($endpoint) === '' && ! $useEnvR2) {
            return $this->jsonFail('Informe o Endpoint R2 (https://<account_id>.r2.cloudflarestorage.com).');
        }

        $sampleUrl = $this->probeS3(
            $provider,
            (string) $key,
            (string) $secret,
            (string) $bucket,
            (string) $region,
            trim($endpoint),
            $publicUrl
        );

        $message = 'Conexão estabelecida com sucesso.';
        if ($provider === 'r2' && $publicUrl !== '') {
            $message .= ' URL pública configurada.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'sample_public_url' => $sampleUrl,
        ]);
    }

    private function probeS3(
        string $provider,
        string $key,
        string $secret,
        string $bucket,
        string $region,
        string $endpoint,
        string $publicUrl
    ): ?string {
        $isR2 = $provider === 'r2' || $this->isR2ApiEndpoint($endpoint);

        $clientConfig = [
            'version' => 'latest',
            'region' => $region !== '' ? $region : ($isR2 ? 'auto' : 'us-east-1'),
            'credentials' => ['key' => $key, 'secret' => $secret],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];

        if ($endpoint !== '') {
            $clientConfig['endpoint'] = $endpoint;
            $clientConfig['use_path_style_endpoint'] = $this->isR2ApiEndpoint($endpoint)
                || str_contains($endpoint, 'wasabisys.com');
        }

        $client = new \Aws\S3\S3Client($clientConfig);

        $client->listObjectsV2([
            'Bucket' => $bucket,
            'MaxKeys' => 1,
        ]);

        $publicUrl = $this->normalizePublicBaseUrl($publicUrl);
        if ($publicUrl === '') {
            return null;
        }

        $probeKey = '.getfy-storage-test-'.uniqid('', true).'.txt';
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $probeKey,
            'Body' => 'ok',
        ]);
        $sampleUrl = rtrim($publicUrl, '/').'/'.$probeKey;
        $client->deleteObject([
            'Bucket' => $bucket,
            'Key' => $probeKey,
        ]);

        return $sampleUrl;
    }

    private function normalizePublicBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (! preg_match('#^https?://#i', $url)) {
            if (preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}#i', $url)) {
                $url = 'https://'.ltrim($url, '/');
            }
        }

        return rtrim($url, '/');
    }

    private function isR2ApiEndpoint(?string $url): bool
    {
        return $url !== null && $url !== '' && str_contains(strtolower($url), 'r2.cloudflarestorage.com');
    }

    private function friendlyAwsMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);

        if (str_contains($lower, 'checksum-crc32') || str_contains($lower, 'not implemented')) {
            return 'R2 rejeitou checksum do AWS SDK. Atualize o código (update.sh) e confira endpoint/URL pública.';
        }
        if (str_contains($lower, 'access denied') || str_contains($lower, '403')) {
            return 'Acesso negado: verifique Access Key, Secret e permissões do token R2.';
        }
        if (str_contains($lower, 'could not resolve host')) {
            return 'Não foi possível resolver o host do endpoint. Confira o Endpoint R2.';
        }

        return $msg !== '' ? $msg : 'Erro ao conectar ao storage.';
    }

    private function jsonFail(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => $message,
        ], 422);
    }
}
