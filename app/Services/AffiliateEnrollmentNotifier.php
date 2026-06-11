<?php

namespace App\Services;

use App\Jobs\SendAffiliateEnrollmentApprovedMailJob;
use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use Illuminate\Support\Collection;

class AffiliateEnrollmentNotifier
{
    public function __construct(
        protected PanelPushService $panelPushService
    ) {}

    public function notifyApproved(ProductAffiliateEnrollment $enrollment): void
    {
        $enrollment->loadMissing(['product', 'affiliate']);
        if ($enrollment->status !== ProductAffiliateEnrollment::STATUS_APPROVED) {
            return;
        }

        $affiliate = $enrollment->affiliate;
        $product = $enrollment->product;
        if (! $affiliate || ! $product) {
            return;
        }

        $panelUrl = route('produtos.painel-afiliado.show', $product->id);
        $title = 'Afiliação aprovada: '.$product->name;
        $body = 'Sua solicitação foi aprovada. Acesse o painel do afiliado para copiar seu link exclusivo de divulgação.';

        PanelNotification::firstOrCreate(
            [
                'user_id' => $affiliate->id,
                'event_key' => 'affiliate_enrollment_'.$enrollment->id,
            ],
            [
                'tenant_id' => $affiliate->tenant_id,
                'type' => 'affiliate_enrollment_approved',
                'title' => $title,
                'body' => $body,
                'url' => $panelUrl,
            ]
        );

        $subscriptions = PanelPushSubscription::query()
            ->where('user_id', $affiliate->id)
            ->get();
        if ($subscriptions instanceof Collection && $subscriptions->isNotEmpty()) {
            $this->panelPushService->sendToSubscriptions($subscriptions, $title, $body, $panelUrl);
        }

        SendAffiliateEnrollmentApprovedMailJob::dispatch($enrollment->id);
    }

    public static function affiliateCheckoutLink(Product $product, ProductAffiliateEnrollment $enrollment): ?string
    {
        if (! $enrollment->public_ref || ! $product->checkout_slug) {
            return null;
        }

        $checkoutUrl = url('/c/'.$product->checkout_slug);

        return $checkoutUrl.(str_contains($checkoutUrl, '?') ? '&' : '?').'ref='.urlencode($enrollment->public_ref);
    }
}
