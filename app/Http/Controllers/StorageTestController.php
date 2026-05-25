<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\StorageConnectionTester;
use App\Support\PlatformConfigContext;
use App\Support\RemoteStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StorageTestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            return $this->runTest($request);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return response()->json([
                'success' => false,
                'message' => is_string($first) && $first !== '' ? $first : 'Dados inválidos.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::warning('storage.test_failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => StorageConnectionTester::friendlyMessage($e),
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function runTest(Request $request): JsonResponse
    {
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
        $publicUrlInput = RemoteStorage::normalizePublicBaseUrl((string) $request->input('storage_s3_url', ''));

        $useEnvR2 = $cloudMode
            && $provider === 'r2'
            && $r2EnvConfigured
            && trim($keyInput) === ''
            && trim($bucketInput) === ''
            && trim($endpointInput) === ''
            && $publicUrlInput === ''
            && trim((string) $request->input('storage_s3_secret', '')) === '';

        if (! $useEnvR2) {
            $request->validate([
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
        }

        $tenantId = PlatformConfigContext::settingsTenantId();
        $key = $useEnvR2 ? $r2EnvKey : (string) $request->input('storage_s3_key');
        $secret = $useEnvR2 ? $r2EnvSecret : $request->input('storage_s3_secret');
        if ($secret === null || $secret === '') {
            $secretRaw = Setting::get('storage_s3_secret', '', $tenantId);
            if ($secretRaw !== '') {
                try {
                    $secret = Crypt::decryptString($secretRaw);
                } catch (\Throwable) {
                    $secret = '';
                }
            }
        }
        if ($secret === null || $secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'O campo Secret Key é obrigatório. Preencha e salve as configurações uma vez para que fique guardado.',
            ], 422);
        }

        $bucket = $useEnvR2 ? $r2EnvBucket : (string) $request->input('storage_s3_bucket');
        $region = $provider === 'r2' ? 'auto' : (string) $request->input('storage_s3_region', 'us-east-1');
        $endpoint = $useEnvR2 ? $r2EnvEndpoint : (string) $request->input('storage_s3_endpoint', '');
        $publicUrl = $useEnvR2
            ? RemoteStorage::normalizePublicBaseUrl($r2EnvPublicUrl)
            : $publicUrlInput;

        if (RemoteStorage::requiresPublicBaseUrl($provider) && $publicUrl === '') {
            return response()->json([
                'success' => false,
                'message' => 'Para Cloudflare R2, informe a URL pública (ex.: https://pub-xxxx.r2.dev ou https://media.seudominio.com). O endpoint da API não abre imagens no navegador.',
            ], 422);
        }

        if ($publicUrl !== '' && RemoteStorage::isR2ApiEndpoint($publicUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'A URL pública não pode ser o endpoint da API (*.r2.cloudflarestorage.com). Use pub-*.r2.dev ou domínio customizado.',
            ], 422);
        }

        if ($provider === 'r2' && trim($endpoint) === '' && ! $useEnvR2) {
            return response()->json([
                'success' => false,
                'message' => 'Informe o Endpoint R2 (https://<account_id>.r2.cloudflarestorage.com).',
            ], 422);
        }

        $result = StorageConnectionTester::test([
            'provider' => $provider,
            'key' => $key,
            'secret' => (string) $secret,
            'bucket' => $bucket,
            'region' => $region,
            'endpoint' => $endpoint,
        ], $publicUrl);

        $message = 'Conexão estabelecida com sucesso.';
        if ($provider === 'r2' && $publicUrl !== '') {
            $message .= ' URL pública configurada.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'sample_public_url' => $result['sample_public_url'],
        ]);
    }
}
