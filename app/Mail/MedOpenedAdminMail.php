<?php

namespace App\Mail;

use App\Models\MedDispute;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MedOpenedAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{app_name: string, theme_primary: string, logo_url: ?string}  $branding
     */
    public function __construct(
        public MedDispute $dispute,
        public array $branding,
        public string $disputeUrl
    ) {
        $this->subject('MED aberta (plataforma) — disputa #'.$dispute->id);
    }

    public function build(): self
    {
        $dispute = $this->dispute;
        $dispute->loadMissing(['order.product', 'tenantOwner']);

        return $this->view('emails.med-opened-admin', [
            'branding' => $this->branding,
            'dispute' => $dispute,
            'order' => $dispute->order,
            'merchantName' => $dispute->tenantOwner?->name,
            'productName' => $dispute->order?->product?->name,
            'disputeUrl' => $this->disputeUrl,
        ]);
    }
}
