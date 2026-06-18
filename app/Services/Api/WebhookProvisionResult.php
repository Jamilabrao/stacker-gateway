<?php

namespace App\Services\Api;

use App\Models\ApiApplication;

final class WebhookProvisionResult
{
    public function __construct(
        public ApiApplication $application,
        public ?string $revealedSecret = null,
    ) {}
}
