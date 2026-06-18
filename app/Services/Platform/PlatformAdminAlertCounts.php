<?php

namespace App\Services\Platform;

use App\Models\MedDispute;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Support\Facades\Schema;

class PlatformAdminAlertCounts
{
    /**
     * Contadores para badges do menu do operador (/plataforma).
     *
     * @return array<string, int>
     */
    public function sidebarBadges(): array
    {
        return [
            'kyc' => $this->kycPendingCount(),
            'saques' => $this->withdrawalsAttentionCount(),
            'financeiro' => $this->withdrawalsPendingCount(),
            'disputas' => $this->medDisputesOpenCount(),
            'transacoes' => $this->refundRequestsPendingCount(),
        ];
    }

    public function kycPendingCount(): int
    {
        if (! Schema::hasColumn('users', 'kyc_status')) {
            return 0;
        }

        return (int) User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNotNull('tenant_id')
            ->where(function ($sub) {
                $sub->where('kyc_status', User::KYC_PENDING_REVIEW);
                if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
                    $sub->orWhere('kyc_needs_document_review', true);
                }
            })
            ->count();
    }

    public function withdrawalsPendingCount(): int
    {
        if (! Schema::hasTable('withdrawals')) {
            return 0;
        }

        return (int) Withdrawal::query()
            ->where('status', MerchantWithdrawalService::STATUS_PENDING)
            ->count();
    }

    public function withdrawalsFailedCount(): int
    {
        if (! Schema::hasTable('withdrawals')) {
            return 0;
        }

        return (int) Withdrawal::query()
            ->where('status', MerchantWithdrawalService::STATUS_FAILED)
            ->count();
    }

    public function withdrawalsAttentionCount(): int
    {
        return $this->withdrawalsPendingCount() + $this->withdrawalsFailedCount();
    }

    public function medDisputesOpenCount(): int
    {
        if (! Schema::hasTable('med_disputes')) {
            return 0;
        }

        if (! Schema::hasColumn('med_disputes', 'responsible_party')) {
            return (int) MedDispute::query()->open()->count();
        }

        return (int) MedDispute::query()->platformManaged()->open()->count();
    }

    public function refundRequestsPendingCount(): int
    {
        if (! Schema::hasTable('refund_requests')) {
            return 0;
        }

        return (int) RefundRequest::query()->pending()->count();
    }
}
