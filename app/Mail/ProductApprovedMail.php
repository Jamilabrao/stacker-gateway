<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{app_name: string, theme_primary: string, logo_url: ?string}  $branding
     */
    public function __construct(
        public User $seller,
        public Product $product,
        public array $branding,
        public string $panelUrl,
    ) {
        $this->subject('Seu produto foi aprovado');
    }

    public function build(): self
    {
        return $this->view('emails.product-approved', [
            'branding' => $this->branding,
            'recipientName' => $this->seller->name,
            'productName' => $this->product->name,
            'panelUrl' => $this->panelUrl,
        ]);
    }
}
