<?php

namespace App\Policies;

use App\Models\RefundRequest;
use App\Models\User;

class RefundRequestPolicy
{
    public function view(User $user, RefundRequest $refundRequest): bool
    {
        return $this->ownsTenant($user, $refundRequest->tenant_id);
    }

    public function update(User $user, RefundRequest $refundRequest): bool
    {
        return $this->ownsTenant($user, $refundRequest->tenant_id);
    }

    private function ownsTenant(User $user, ?int $tenantId): bool
    {
        if ($user->role === User::ROLE_ADMIN && $tenantId === null) {
            return true;
        }

        return $user->tenant_id !== null && (int) $user->tenant_id === (int) $tenantId;
    }
}
