<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlatformEmailNotifications;
use App\Support\EmailVerificationResendGuard;
use App\Support\RegistrationEmailVerificationSettings;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function __construct(
        protected PlatformEmailNotifications $platformEmailNotifications
    ) {}

    public function notice(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        if (! RegistrationEmailVerificationSettings::requiresVerificationFor($user)) {
            return redirect($user->sellerPanelFallbackUrl());
        }

        return Inertia::render('Auth/VerifyEmail', [
            'email' => $user->email,
            'resend_available_in_seconds' => EmailVerificationResendGuard::secondsUntilResendAllowed($user),
            'resend_cooldown_seconds' => EmailVerificationResendGuard::COOLDOWN_SECONDS,
        ]);
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'Link de verificação inválido.');
        }

        if (! $request->hasValidSignature()) {
            if (Auth::check()) {
                return redirect()->route('verification.notice')
                    ->with('error', 'O link de verificação expirou. Solicite um novo e-mail.');
            }

            return redirect()->route('login')
                ->with('error', 'O link de verificação expirou. Faça login e solicite um novo e-mail.');
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            event(new Verified($user));
        }

        if (Auth::id() === $user->id) {
            return redirect('/financeiro?tab=seus-dados')
                ->with('success', 'E-mail confirmado! Agora envie seus documentos de verificação (KYC).');
        }

        return redirect()->route('login')
            ->with('success', 'E-mail confirmado! Faça login para continuar.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        if (! RegistrationEmailVerificationSettings::requiresVerificationFor($user)) {
            return redirect($user->sellerPanelFallbackUrl());
        }

        $waitSeconds = EmailVerificationResendGuard::secondsUntilResendAllowed($user);
        if ($waitSeconds > 0) {
            return back()->with('error', "Aguarde {$waitSeconds} segundos antes de solicitar outro e-mail.");
        }

        if (! $this->platformEmailNotifications->sendEmailVerification($user)) {
            return back()->with(
                'error',
                'Não foi possível enviar o e-mail de confirmação. Em Plataforma → Configurações → E-mail, salve o SMTP e confirme com o teste de envio.'
            );
        }

        EmailVerificationResendGuard::markResent($user);

        return back()->with('success', 'Enviamos um novo e-mail de confirmação.');
    }

    public static function signedVerificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );
    }
}
