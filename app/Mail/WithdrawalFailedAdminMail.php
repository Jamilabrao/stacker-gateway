<?php

namespace App\Mail;

use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WithdrawalFailedAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{app_name: string, theme_primary: string, logo_url: ?string}  $branding
     */
    public function __construct(
        public Withdrawal $withdrawal,
        public ?string $merchantName,
        public ?string $merchantEmail,
        public string $reason,
        public array $branding,
        public string $reviewUrl
    ) {
        $this->subject('Falha no saque #'.$this->withdrawal->id.' — '.$this->branding['app_name']);
    }

    public function build(): self
    {
        return $this->view('emails.withdrawal-failed-admin', [
            'branding' => $this->branding,
            'withdrawal' => $this->withdrawal,
            'merchantName' => $this->merchantName,
            'merchantEmail' => $this->merchantEmail,
            'reason' => $this->reason,
            'reviewUrl' => $this->reviewUrl,
        ]);
    }
}
