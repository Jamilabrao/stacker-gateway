<?php

namespace App\Services\Med;

use App\Models\MedDispute;
use App\Models\User;
use App\Services\MedEmailNotifications;
use App\Services\PlatformOrderAdminService;
use InvalidArgumentException;

class MedResolutionService
{
    public function __construct(
        protected MedPolicyService $policy,
        protected MedEmailNotifications $notifications,
    ) {}

    public function resolve(MedDispute $dispute, string $outcome, User $admin, ?string $note = null): MedDispute
    {
        $outcome = strtolower(trim($outcome));
        if (! in_array($outcome, ['won', 'lost', 'cancelled'], true)) {
            throw new InvalidArgumentException('Resultado inválido. Use won, lost ou cancelled.');
        }

        if (! $dispute->isOpen() && $dispute->status !== MedDispute::STATUS_DEFENSE_SUBMITTED) {
            throw new InvalidArgumentException('Esta disputa já foi encerrada.');
        }

        $mappedStatus = match ($outcome) {
            'won' => MedDispute::STATUS_RESOLVED_WON,
            'lost' => MedDispute::STATUS_RESOLVED_LOST,
            'cancelled' => MedDispute::STATUS_CANCELLED,
        };

        $dispute->update([
            'status' => $mappedStatus,
            'outcome' => $outcome,
            'resolved_at' => now(),
            'resolved_by_user_id' => $admin->id,
            'resolution_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);

        $this->applyWalletOutcome($dispute->fresh(), $outcome);

        $this->notifications->medResolved($dispute->fresh());

        return $dispute->fresh();
    }

    public function applyWalletOutcome(MedDispute $dispute, string $outcome): void
    {
        if (! $this->policy->shouldHoldTenantBalance($dispute)) {
            return;
        }

        $order = $dispute->order;
        if ($order === null) {
            return;
        }

        $freshOrder = $order->fresh();

        if ($outcome === 'lost') {
            if (in_array($freshOrder->status, ['completed', 'disputed'], true)) {
                PlatformOrderAdminService::refundPaidOrDisputed($freshOrder);
            }

            return;
        }

        if (in_array($outcome, ['won', 'cancelled'], true) && $freshOrder->status === 'disputed') {
            PlatformOrderAdminService::releaseMedHoldAndComplete($freshOrder);
        }
    }
}
