<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\SellerActivityLogService;

trait LogsSellerActivity
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function logSellerActivity(
        string $action,
        mixed $target = null,
        array $metadata = [],
        ?User $actor = null,
        ?int $tenantId = null,
        ?string $source = null,
    ): void {
        $actor = $actor ?? (auth()->user() instanceof User ? auth()->user() : null);

        $targetType = is_object($target) ? $target::class : null;
        $targetId = null;
        if (is_object($target)) {
            $targetId = $target->id ?? null;
        } elseif (is_scalar($target) && $target !== '') {
            $targetId = $target;
        }

        SellerActivityLogService::record(
            actor: $actor,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            metadata: $metadata,
            tenantId: $tenantId,
            source: $source,
        );
    }
}
