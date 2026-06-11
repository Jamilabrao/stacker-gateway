<?php

namespace App\Mail;

use App\Models\RefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RefundRequestAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{app_name: string, theme_primary: string, logo_url: ?string}  $branding
     */
    public function __construct(
        public RefundRequest $refundRequest,
        public array $branding,
        public string $transactionsUrl
    ) {
        $this->subject('Nova solicitação de reembolso — '.$this->branding['app_name']);
    }

    public function build(): self
    {
        $order = $this->refundRequest->order;

        return $this->view('emails.refund-request-admin', [
            'branding' => $this->branding,
            'refundRequest' => $this->refundRequest,
            'order' => $order,
            'productName' => $order?->product?->name,
            'customerEmail' => $order?->email,
            'transactionsUrl' => $this->transactionsUrl,
        ]);
    }
}
