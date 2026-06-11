<?php

namespace App\Jobs;

use App\Mail\AffiliateEnrollmentApprovedMail;
use App\Models\ProductAffiliateEnrollment;
use App\Services\AffiliateEnrollmentNotifier;
use App\Services\BrandingEmailData;
use App\Services\PlatformTransactionalMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAffiliateEnrollmentApprovedMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $enrollmentId
    ) {}

    public function handle(PlatformTransactionalMailService $mailService): void
    {
        $enrollment = ProductAffiliateEnrollment::with(['product', 'affiliate'])
            ->find($this->enrollmentId);

        if (! $enrollment || $enrollment->status !== ProductAffiliateEnrollment::STATUS_APPROVED) {
            return;
        }

        $affiliate = $enrollment->affiliate;
        $product = $enrollment->product;
        if (! $affiliate?->email || ! $product) {
            return;
        }

        $branding = BrandingEmailData::forTenant(null);
        $panelUrl = route('produtos.painel-afiliado.show', $product->id);
        $affiliateLink = AffiliateEnrollmentNotifier::affiliateCheckoutLink($product, $enrollment);
        $materialsUrl = $product->affiliate_page_url ?: null;

        $mailService->send(
            new AffiliateEnrollmentApprovedMail(
                $affiliate,
                $product,
                $branding,
                $panelUrl,
                $affiliateLink,
                $materialsUrl
            ),
            $affiliate->email
        );
    }
}
