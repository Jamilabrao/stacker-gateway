<?php

namespace App\Mail;

use App\Models\MedDispute;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MedOpenedSellerMail extends Mailable
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
        $orderRef = $dispute->order?->public_reference ?? (string) ($dispute->order_id ?? '');
        $this->subject('Disputa MED aberta — pedido #'.$orderRef);
    }

    public function build(): self
    {
        $dispute = $this->dispute;
        $dispute->loadMissing('order.product');

        return $this->view('emails.med-opened-seller', [
            'branding' => $this->branding,
            'dispute' => $dispute,
            'order' => $dispute->order,
            'productName' => $dispute->order?->product?->name,
            'disputeUrl' => $this->disputeUrl,
        ]);
    }
}
