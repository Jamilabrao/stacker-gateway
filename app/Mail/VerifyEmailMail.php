<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{app_name: string, theme_primary: string, logo_url: ?string}  $branding
     */
    public function __construct(
        public User $user,
        public array $branding,
        public string $verificationUrl,
        public int $expireMinutes = 60,
    ) {
        $this->subject('Confirme seu e-mail — '.$this->branding['app_name']);
    }

    public function build(): self
    {
        return $this->view('emails.verify-email', [
            'branding' => $this->branding,
            'recipientName' => $this->user->name,
            'verificationUrl' => $this->verificationUrl,
            'expireMinutes' => $this->expireMinutes,
        ]);
    }
}
