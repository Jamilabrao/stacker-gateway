<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * E-mails transacionais da plataforma (SMTP global em settings, tenant_id null).
 */
class PlatformTransactionalMailService
{
    public function __construct(
        protected TenantMailConfigService $mailConfig
    ) {}

    /**
     * @param  string|list<string>  $to
     */
    public function send(Mailable $mailable, string|array $to): bool
    {
        $recipients = is_array($to) ? array_values(array_filter($to)) : [trim($to)];
        if ($recipients === []) {
            return false;
        }

        if (! $this->mailConfig->isEmailConfigured(null)) {
            Log::warning('PlatformTransactionalMailService: SMTP da plataforma não configurado.', [
                'to' => $recipients,
                'mailable' => $mailable::class,
            ]);

            return false;
        }

        $this->mailConfig->applyPlatformGlobalMailerConfig();
        config(['mail.default' => 'smtp']);
        Mail::purge('smtp');

        try {
            $this->mailConfig->assertSmtpHostIsConfigured();
            Mail::mailer('smtp')->to($recipients)->send($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::warning('PlatformTransactionalMailService: falha ao enviar e-mail.', [
                'to' => $recipients,
                'mailable' => $mailable::class,
                'host' => config('mail.mailers.smtp.host'),
                'provider' => $this->mailConfig->getProviderForTenant(null),
                'message' => $e->getMessage(),
            ]);
            report($e);

            return false;
        }
    }
}
