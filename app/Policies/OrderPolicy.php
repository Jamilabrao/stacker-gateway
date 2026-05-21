<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $this->ownsTenant($user, $order->tenant_id);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->ownsTenant($user, $order->tenant_id);
    }

    private function ownsTenant(User $user, ?int $tenantId): bool
    {
        if ($user->role === User::ROLE_ADMIN && $tenantId === null) {
            return true;
        }

        return $user->tenant_id !== null && (int) $user->tenant_id === (int) $tenantId;
    }
}
