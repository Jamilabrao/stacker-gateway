<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\User;

class AffiliateEnrollmentService
{
    public const OUTCOME_OWN_PRODUCT = 'own_product';

    public const OUTCOME_UNAVAILABLE = 'unavailable';

    public const OUTCOME_INVALID_COMMISSION = 'invalid_commission';

    public const OUTCOME_ALREADY_APPROVED = 'already_approved';

    public const OUTCOME_ALREADY_PENDING = 'already_pending';

    public const OUTCOME_REQUESTED = 'requested';

    public const OUTCOME_APPROVED = 'approved';

    /**
     * @return array{ok: bool, outcome: string, flash: string, message: string, enrollment: ?ProductAffiliateEnrollment}
     */
    public function requestEnrollment(Product $product, User $user, bool $requireShowcase = true): array
    {
        if ((string) $product->tenant_id === (string) $user->tenant_id) {
            return $this->result(false, self::OUTCOME_OWN_PRODUCT, 'error', 'Você não pode se afiliar ao próprio produto.');
        }

        $available = $product->affiliate_enabled && $product->isAvailableForPurchase();
        if ($requireShowcase) {
            $available = $available && (bool) $product->affiliate_show_in_showcase;
        }

        if (! $available) {
            return $this->result(false, self::OUTCOME_UNAVAILABLE, 'error', 'Este produto não está disponível para afiliação.');
        }

        if (! $product->affiliateCommissionTotalsValid()) {
            return $this->result(false, self::OUTCOME_INVALID_COMMISSION, 'error', 'Este produto não pode aceitar afiliados no momento (comissões inválidas).');
        }

        $enrollment = ProductAffiliateEnrollment::query()
            ->where('product_id', $product->id)
            ->where('affiliate_user_id', $user->id)
            ->first();

        if ($enrollment) {
            if ($enrollment->status === ProductAffiliateEnrollment::STATUS_APPROVED) {
                return $this->result(true, self::OUTCOME_ALREADY_APPROVED, 'info', 'Você já é afiliado deste produto.', $enrollment);
            }
            if ($enrollment->status === ProductAffiliateEnrollment::STATUS_PENDING) {
                return $this->result(true, self::OUTCOME_ALREADY_PENDING, 'info', 'Sua solicitação já está pendente.', $enrollment);
            }
            if (in_array($enrollment->status, [ProductAffiliateEnrollment::STATUS_REJECTED, ProductAffiliateEnrollment::STATUS_REVOKED], true)) {
                $enrollment->update([
                    'status' => ProductAffiliateEnrollment::STATUS_PENDING,
                    'public_ref' => null,
                ]);
            }
        } else {
            $enrollment = ProductAffiliateEnrollment::query()->create([
                'product_id' => $product->id,
                'affiliate_user_id' => $user->id,
                'status' => ProductAffiliateEnrollment::STATUS_PENDING,
                'public_ref' => null,
            ]);
        }

        $autoApproved = ! $product->affiliate_manual_approval;
        if ($autoApproved) {
            $enrollment->refresh();
            $enrollment->update(['status' => ProductAffiliateEnrollment::STATUS_APPROVED]);
            $enrollment->ensurePublicRef();
            app(AffiliateEnrollmentNotifier::class)->notifyApproved($enrollment->fresh());
        }

        SellerActivityLogService::record(
            actor: $user,
            action: SellerActivityLogService::AFFILIATE_ENROLLED,
            targetType: ProductAffiliateEnrollment::class,
            targetId: $enrollment->id,
            metadata: [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'auto_approved' => $autoApproved,
                'via' => $requireShowcase ? 'showcase' : 'invite_link',
            ],
        );

        $enrollment = $enrollment->fresh();

        return $this->result(
            true,
            $autoApproved ? self::OUTCOME_APPROVED : self::OUTCOME_REQUESTED,
            'success',
            $autoApproved ? 'Você foi aprovado como afiliado.' : 'Solicitação enviada ao produtor.',
            $enrollment,
        );
    }

    /**
     * @return array{ok: bool, outcome: string, flash: string, message: string, enrollment: ?ProductAffiliateEnrollment}
     */
    private function result(
        bool $ok,
        string $outcome,
        string $flash,
        string $message,
        ?ProductAffiliateEnrollment $enrollment = null,
    ): array {
        return [
            'ok' => $ok,
            'outcome' => $outcome,
            'flash' => $flash,
            'message' => $message,
            'enrollment' => $enrollment,
        ];
    }
}
