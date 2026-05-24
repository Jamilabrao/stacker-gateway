<?php

namespace App\Support;

use App\Models\WalletTransaction;

final class WalletCreditReference
{
    public static function forDirectSale(int $orderId, int $tenantId): string
    {
        return self::build($orderId, $tenantId, WalletTransaction::TYPE_CREDIT_SALE);
    }

    public static function forPendingSale(int $orderId, int $tenantId, string $portion): string
    {
        return self::build($orderId, $tenantId, WalletTransaction::TYPE_CREDIT_SALE_PENDING, $portion);
    }

    private static function build(int $orderId, int $tenantId, string $type, ?string $portion = null): string
    {
        $ref = "o{$orderId}:t{$tenantId}:{$type}";
        if ($portion !== null && $portion !== '') {
            $ref .= ':'.$portion;
        }

        return $ref;
    }
}
