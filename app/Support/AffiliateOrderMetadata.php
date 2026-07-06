<?php

namespace App\Support;

use App\Models\CheckoutSession;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;

final class AffiliateOrderMetadata
{
    /**
     * @param  array<string, mixed>  $orderMetadata
     * @return array<string, mixed>
     */
    public static function merge(
        array $orderMetadata,
        Product $product,
        ?string $affiliateRef = null,
        ?CheckoutSession $checkoutSession = null,
    ): array {
        if (! empty($orderMetadata['affiliate_enrollment_id'])) {
            return $orderMetadata;
        }

        $ref = trim((string) ($affiliateRef ?? ''));
        if ($ref === '' && $checkoutSession !== null) {
            $ref = trim((string) ($checkoutSession->affiliate_ref ?? ''));
        }
        if ($ref === '') {
            return $orderMetadata;
        }

        $enrollment = ProductAffiliateEnrollment::findApprovedByRefForProduct($ref, $product);
        if ($enrollment === null || ! $product->affiliate_enabled) {
            return $orderMetadata;
        }

        if ((int) $enrollment->affiliate_user_id === (int) $product->tenant_id) {
            return $orderMetadata;
        }

        $orderMetadata['affiliate_user_id'] = $enrollment->affiliate_user_id;
        $orderMetadata['affiliate_enrollment_id'] = $enrollment->id;
        $orderMetadata['affiliate_ref'] = $ref;

        return $orderMetadata;
    }
}
