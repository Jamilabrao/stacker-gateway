<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ManualApprovalPinResetAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{app_name: string, theme_primary: string, logo_url: ?string}  $branding
     */
    public function __construct(
        public string $pin,
        public User $requestedBy,
        public array $branding,
        public string $settingsUrl
    ) {
        $this->subject('Novo PIN de aprovação manual — '.$this->branding['app_name']);
    }

    public function build(): self
    {
        return $this->view('emails.manual-approval-pin-reset-admin', [
            'branding' => $this->branding,
            'pin' => $this->pin,
            'requestedBy' => $this->requestedBy,
            'settingsUrl' => $this->settingsUrl,
        ]);
    }
}
