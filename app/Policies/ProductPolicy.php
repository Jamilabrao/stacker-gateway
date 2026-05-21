<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return $this->ownsTenant($user, $product->tenant_id);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->ownsTenant($user, $product->tenant_id);
    }

    private function ownsTenant(User $user, ?int $tenantId): bool
    {
        if ($user->role === User::ROLE_ADMIN && $tenantId === null) {
            return true;
        }

        return $user->tenant_id !== null && (int) $user->tenant_id === (int) $tenantId;
    }
}
