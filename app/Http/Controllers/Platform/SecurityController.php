<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function beginTotp(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->canAccessPlatformPanel()) {
            abort(403);
        }

        $data = PlatformTotpService::beginEnrollment($user->fresh());

        return response()->json([
            'secret' => $data['secret'],
            'otpauth_url' => $data['otpauth_url'],
        ]);
    }

    public function confirmTotp(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->canAccessPlatformPanel()) {
            abort(403);
        }

        $validated = $request->validate([
            'totp_code' => ['required', 'string', 'max:16'],
        ]);

        if (! PlatformTotpService::confirmEnrollment($user->fresh(), $validated['totp_code'])) {
            return redirect()->route('plataforma.profile.index')
                ->with('error', 'Código 2FA inválido. Tente novamente.');
        }

        PlatformAuditService::log('platform.security.totp_enabled', [], $request);

        return redirect()->route('plataforma.profile.index')
            ->with('success', 'Autenticação em dois fatores ativada.');
    }

    public function disableTotp(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->canAccessPlatformPanel()) {
            abort(403);
        }

        $validated = $request->validate([
            'totp_code' => ['required', 'string', 'max:16'],
        ]);

        if (! PlatformTotpService::disable($user->fresh(), $validated['totp_code'])) {
            return redirect()->route('plataforma.profile.index')
                ->with('error', 'Código 2FA inválido.');
        }

        PlatformAuditService::log('platform.security.totp_disabled', [], $request);

        return redirect()->route('plataforma.profile.index')
            ->with('success', 'Autenticação em dois fatores desativada.');
    }
}
