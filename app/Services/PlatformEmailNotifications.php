<?php

namespace App\Services;

use App\Mail\KycApprovedMail;
use App\Mail\KycRejectedMail;
use App\Mail\KycSubmittedAdminMail;
use App\Mail\RefundRequestAdminMail;
use App\Mail\WelcomeInfoprodutorMail;
use App\Mail\ManualApprovalPinResetAdminMail;
use App\Mail\WithdrawalFailedAdminMail;
use App\Mail\WithdrawalPayoutErrorAdminMail;
use App\Models\RefundRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\KycNotificationEmails;
use Illuminate\Support\Facades\Log;

class PlatformEmailNotifications
{
    public function __construct(
        protected PlatformTransactionalMailService $mail
    ) {}

    public function kycSubmitted(User $merchant): void
    {
        $emails = $this->adminRecipients();
        if ($emails === []) {
            return;
        }

        $branding = BrandingEmailData::forTenant(null);
        $reviewUrl = route('plataforma.kyc.show', ['user' => $merchant->id]);

        $this->mail->send(
            new KycSubmittedAdminMail($merchant, $branding, $reviewUrl),
            $emails
        );
    }

    public function withdrawalFailed(Withdrawal $withdrawal, string $reason): void
    {
        $emails = $this->adminRecipients();
        if ($emails === []) {
            return;
        }

        $withdrawal->loadMissing('tenantOwner');
        $owner = $withdrawal->tenantOwner;
        $branding = BrandingEmailData::forTenant(null);

        $this->mail->send(
            new WithdrawalFailedAdminMail(
                $withdrawal,
                $owner?->name,
                $owner?->email,
                $reason,
                $branding,
                route('plataforma.saques.index', ['withdrawal_status' => 'failed'])
            ),
            $emails
        );
    }

    public function withdrawalPayoutError(Withdrawal $withdrawal, string $reason): void
    {
        $emails = $this->adminRecipients();
        if ($emails === []) {
            return;
        }

        $withdrawal->loadMissing('tenantOwner');
        $owner = $withdrawal->tenantOwner;
        $branding = BrandingEmailData::forTenant(null);

        $this->mail->send(
            new WithdrawalPayoutErrorAdminMail(
                $withdrawal,
                $owner?->name,
                $owner?->email,
                $reason,
                $branding,
                route('plataforma.saques.index', ['withdrawal_status' => 'pending'])
            ),
            $emails
        );
    }

    public function refundRequested(RefundRequest $refundRequest): void
    {
        $emails = $this->adminRecipients();
        if ($emails === []) {
            return;
        }

        $refundRequest->loadMissing(['order.product']);
        $branding = BrandingEmailData::forTenant(null);

        $this->mail->send(
            new RefundRequestAdminMail(
                $refundRequest,
                $branding,
                route('plataforma.transacoes.index')
            ),
            $emails
        );
    }

    public function kycApproved(User $merchant): void
    {
        $branding = BrandingEmailData::forTenant($merchant->tenant_id);
        $dashboardUrl = url('/dashboard');

        $this->mail->send(
            new KycApprovedMail($merchant, $branding, $dashboardUrl),
            $merchant->email
        );
    }

    public function kycRejected(User $merchant, string $reason): void
    {
        $branding = BrandingEmailData::forTenant($merchant->tenant_id);
        $kycUrl = url('/financeiro?tab=seus-dados');

        $this->mail->send(
            new KycRejectedMail($merchant, $branding, $reason, $kycUrl),
            $merchant->email
        );
    }

    public function manualApprovalPinReset(string $pin, User $requestedBy): void
    {
        $emails = $this->adminRecipients();
        if ($emails === []) {
            return;
        }

        $branding = BrandingEmailData::forTenant(null);
        $settingsUrl = route('plataforma.financeiro.index', ['tab' => 'saques']);

        $this->mail->send(
            new ManualApprovalPinResetAdminMail($pin, $requestedBy, $branding, $settingsUrl),
            $emails
        );
    }

    public function welcomeInfoprodutor(User $user): void
    {
        $branding = BrandingEmailData::forTenant($user->tenant_id);
        $dashboardUrl = url('/dashboard');
        $kycUrl = url('/financeiro?tab=seus-dados');

        $this->mail->send(
            new WelcomeInfoprodutorMail($user, $branding, $dashboardUrl, $kycUrl),
            $user->email
        );
    }

    /**
     * @return list<string>
     */
    private function adminRecipients(): array
    {
        $raw = Setting::get('kyc_notification_emails', '', null);
        $emails = KycNotificationEmails::parse(is_string($raw) ? $raw : null);
        if ($emails === [] && config('app.debug')) {
            Log::debug('PlatformEmailNotifications: nenhum e-mail de alerta configurado (kyc_notification_emails).');
        }

        return $emails;
    }
}
