<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AffiliateEnrollmentApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{app_name: string, theme_primary: string, logo_url: ?string}  $branding
     */
    public function __construct(
        public User $affiliate,
        public Product $product,
        public array $branding,
        public string $panelUrl,
        public ?string $affiliateLink,
        public ?string $materialsUrl
    ) {
        $this->subject('Afiliação aprovada — '.$this->product->name);
    }

    public function build(): self
    {
        return $this->view('emails.affiliate-enrollment-approved', [
            'branding' => $this->branding,
            'recipientName' => $this->affiliate->name,
            'productName' => $this->product->name,
            'panelUrl' => $this->panelUrl,
            'affiliateLink' => $this->affiliateLink,
            'materialsUrl' => $this->materialsUrl,
        ]);
    }
}
