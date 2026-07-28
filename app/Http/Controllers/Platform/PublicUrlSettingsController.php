<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Services\InstallationPublicUrlService;
use App\Services\PlatformAuditService;
use App\Services\Stacker\ContainerRestartRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PublicUrlSettingsController extends Controller
{
    use RequiresPlatformStepUp;

    public function data(
        InstallationPublicUrlService $service,
        ContainerRestartRequestService $restart
    ): JsonResponse {
        return response()->json([
            ...$service->snapshot(),
            'container_restart' => $restart->status(),
        ]);
    }

    public function update(Request $request, InstallationPublicUrlService $service): JsonResponse
    {
        $this->validatePlatformStepUp($request);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:255'],
            'totp_code' => ['nullable', 'string', 'max:16'],
        ]);

        try {
            $result = $service->apply((string) $validated['url']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        PlatformAuditService::log('installation_public_url.updated', [
            'url' => $result['url'],
            'host' => $result['host'],
        ], $request);

        return response()->json([
            'success' => true,
            'message' => 'URL pública da instalação atualizada.',
            ...$result,
            ...$service->snapshot(),
        ]);
    }

    public function restartContainers(
        Request $request,
        ContainerRestartRequestService $restart
    ): JsonResponse {
        $this->validatePlatformStepUp($request);

        $request->validate([
            'totp_code' => ['nullable', 'string', 'max:16'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $restart->request(
            $request->user()?->id,
            (string) $request->input('reason', 'public_url_settings')
        );

        if (($result['status'] ?? '') === 'unavailable') {
            return response()->json(['message' => $result['message']], 422);
        }

        if (($result['status'] ?? '') !== 'pending' && ($result['message'] ?? '') !== '' && ! ($result['can_request'] ?? true)) {
            // Em andamento — ainda devolve 200 com status atual.
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'container_restart' => $restart->status(),
            ]);
        }

        PlatformAuditService::log('installation_containers.restart_requested', [
            'reason' => $request->input('reason', 'public_url_settings'),
        ], $request);

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Reinício solicitado.',
            'container_restart' => $restart->status(),
        ]);
    }

    public function restartStatus(ContainerRestartRequestService $restart): JsonResponse
    {
        return response()->json([
            'container_restart' => $restart->status(),
        ]);
    }
}
