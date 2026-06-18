<?php

namespace App\Mail;

use App\Models\MedDispute;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MedResolvedAdminMail extends Mailable
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
        $outcome = $dispute->outcome ?? $dispute->status;
        $this->subject('MED encerrada ('.$outcome.') — disputa #'.$dispute->id);
    }

    public function build(): self
    {
        $dispute = $this->dispute;
        $dispute->loadMissing(['order.product', 'tenantOwner']);

        return $this->view('emails.med-resolved-admin', [
            'branding' => $this->branding,
            'dispute' => $dispute,
            'order' => $dispute->order,
            'merchantName' => $dispute->tenantOwner?->name,
            'productName' => $dispute->order?->product?->name,
            'disputeUrl' => $this->disputeUrl,
        ]);
    }
}
