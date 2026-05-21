<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TenantMailConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ForgotPasswordController extends Controller
{
    public function __construct(
        protected TenantMailConfigService $mailConfig
    ) {}

    public function showLinkRequestForm(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendResetLinkEmail(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $request->input('email'))->first();

        try {
            $this->mailConfig->applyForPasswordReset($user);
            $this->mailConfig->assertSmtpHostIsConfigured();
            config(['mail.default' => 'smtp']);
            Mail::purge('smtp');

            if ($user?->canAccessPlatformPanel()) {
                app()->instance('password_reset_redirect', '/plataforma/login');
            }
        } catch (Throwable $e) {
            Log::warning('ForgotPassword: SMTP não aplicado antes do envio.', [
                'email' => $request->input('email'),
                'user_id' => $user?->id,
                'tenant_id' => $user?->tenant_id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'email' => [$this->mailErrorMessage($e)],
            ])->onlyInput('email');
        }

        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );
        } catch (Throwable $e) {
            Log::error('ForgotPassword: falha ao enviar link de redefinição.', [
                'email' => $request->input('email'),
                'smtp_host' => config('mail.mailers.smtp.host'),
                'smtp_port' => config('mail.mailers.smtp.port'),
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return back()->withErrors([
                'email' => [$this->mailErrorMessage($e)],
            ])->onlyInput('email');
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors([
                'email' => ['Por favor, aguarde um minuto antes de solicitar um novo link de redefinição de senha.'],
            ])->onlyInput('email');
        }

        return back()->with('status', 'Se o e-mail estiver cadastrado, você receberá o link de redefinição em sua caixa de entrada.');
    }

    private function mailErrorMessage(Throwable $e): string
    {
        $message = 'Não foi possível enviar o e-mail. Verifique as configurações de SMTP em Configurações → E-mail ou tente novamente mais tarde.';
        if (config('app.debug')) {
            $message .= ' Detalhe: '.$e->getMessage();
        }

        return $message;
    }
}
