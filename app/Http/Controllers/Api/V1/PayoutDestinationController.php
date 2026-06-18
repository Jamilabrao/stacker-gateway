<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiAuthContext;
use App\Services\Api\ApiWithdrawalService;
use App\Services\Payout\PayoutDestinationValidator;
use App\Services\Payout\PlatformPayoutGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayoutDestinationController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::WITHDRAWALS_WRITE)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        $request->validate([
            'pix_key' => ['required', 'string', 'max:255'],
            'pix_key_type' => ['required', 'string', 'in:cpf,cnpj,email,phone,evp,random'],
            'key_owner_document' => ['nullable', 'string', 'max:20'],
        ]);

        $slug = PlatformPayoutGateway::activeSlug();
        $result = PayoutDestinationValidator::validateForUpdate([
            'pix_key' => (string) $request->input('pix_key'),
            'pix_key_type' => (string) $request->input('pix_key_type'),
            'key_owner_document' => $request->input('key_owner_document'),
        ], $slug);

        if (! ($result['ok'] ?? false)) {
            throw ValidationException::withMessages([
                $result['field'] => $result['message'],
            ]);
        }

        $owner = ApiWithdrawalService::resolveInfoprodutor((int) $ctx->application->tenant_id);
        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];

        $keyField = match ($slug) {
            'spacepag' => 'spacepag_pix_key',
            'woovi' => 'woovi_pix_key',
            default => 'cajupay_pix_key',
        };
        $typeField = match ($slug) {
            'spacepag' => 'spacepag_pix_key_type',
            'woovi' => 'woovi_pix_key_type',
            default => 'cajupay_pix_key_type',
        };

        $settings[$keyField] = $result['pix_key'];
        $settings[$typeField] = $result['pix_key_type'];
        $settings['payout_pix_key'] = $result['pix_key'];
        $settings['payout_pix_key_type'] = $result['pix_key_type'];

        if ($slug === 'cajupay' && $result['key_owner_document'] !== '') {
            $settings['cajupay_pix_key_owner_document'] = $result['key_owner_document'];
            $settings['payout_pix_key_owner_document'] = $result['key_owner_document'];
        }

        $owner->forceFill(['payout_settings' => $settings])->save();

        $response = [
            'message' => 'Chave PIX de saque atualizada.',
            'pix_key_type' => $result['pix_key_type'],
            'pix_key_masked' => $this->maskPixKey($result['pix_key']),
        ];

        if ($slug === 'cajupay' && $result['key_owner_document'] !== '') {
            $response['key_owner_document_masked'] = PayoutDestinationValidator::maskDocument($result['key_owner_document']);
        }

        return response()->json($response);
    }

    private function maskPixKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 4) {
            return '****';
        }

        return str_repeat('*', max(0, $len - 4)).substr($key, -4);
    }
}
