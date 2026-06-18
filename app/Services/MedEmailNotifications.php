<?php

namespace App\Services;

use App\Mail\MedOpenedAdminMail;
use App\Mail\MedOpenedSellerMail;
use App\Mail\MedResolvedAdminMail;
use App\Mail\MedResolvedSellerMail;
use App\Models\MedDispute;
use App\Models\User;
use App\Support\KycNotificationEmails;
use App\Models\Setting;

class MedEmailNotifications
{
    public function __construct(
        protected PlatformTransactionalMailService $mail,
    ) {}

    public function medOpened(MedDispute $dispute): void
    {
        $dispute->loadMissing(['order.product', 'tenantOwner']);

        if ($dispute->responsible_party === MedDispute::PARTY_TENANT) {
            $owner = $dispute->tenantOwner;
            if ($owner?->email) {
                $branding = BrandingEmailData::forTenant($dispute->tenant_id);
                $this->mail->send(
                    new MedOpenedSellerMail($dispute, $branding, route('disputas.show', $dispute)),
                    $owner->email
                );
            }

            return;
        }

        $emails = $this->adminRecipients();
        if ($emails === []) {
            return;
        }

        $branding = BrandingEmailData::forTenant(null);
        $this->mail->send(
            new MedOpenedAdminMail($dispute, $branding, route('plataforma.disputas.show', $dispute)),
            $emails
        );
    }

    public function medResolved(MedDispute $dispute): void
    {
        $dispute->loadMissing(['order.product', 'tenantOwner']);

        if ($dispute->responsible_party === MedDispute::PARTY_TENANT) {
            $owner = $dispute->tenantOwner;
            if ($owner?->email) {
                $branding = BrandingEmailData::forTenant($dispute->tenant_id);
                $this->mail->send(
                    new MedResolvedSellerMail($dispute, $branding, route('disputas.show', $dispute)),
                    $owner->email
                );
            }
        }

        $emails = $this->adminRecipients();
        if ($emails === []) {
            return;
        }

        $branding = BrandingEmailData::forTenant(null);
        $this->mail->send(
            new MedResolvedAdminMail($dispute, $branding, route('plataforma.disputas.show', $dispute)),
            $emails
        );
    }

    /**
     * @return list<string>
     */
    private function adminRecipients(): array
    {
        $raw = Setting::get('kyc_notification_emails', '', null);

        return KycNotificationEmails::parse(is_string($raw) ? $raw : null);
    }
}
