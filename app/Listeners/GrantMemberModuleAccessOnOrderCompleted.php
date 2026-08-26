<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\MemberModuleAccessService;

class GrantMemberModuleAccessOnOrderCompleted
{
    public function __construct(
        protected MemberModuleAccessService $accessService
    ) {}

    public function handle(OrderCompleted $event): void
    {
        $this->accessService->grantFromOrder($event->order);
    }
}
