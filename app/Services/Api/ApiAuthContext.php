<?php

namespace App\Services\Api;

use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Support\ApiScopes;
use Illuminate\Http\Request;

class ApiAuthContext
{
    public function __construct(
        public ApiApplication $application,
        public ?ApiKey $apiKey = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $app = $request->attributes->get('api_application');
        if (! $app instanceof ApiApplication) {
            abort(500, 'API application not resolved');
        }

        $key = $request->attributes->get('api_key');
        if ($key !== null && ! $key instanceof ApiKey) {
            $key = null;
        }

        return new self($app, $key);
    }

    public function isLegacy(): bool
    {
        if ($this->apiKey !== null) {
            return false;
        }

        return (bool) ($this->application->is_legacy ?? true);
    }

    public function hasScope(string $scope): bool
    {
        if ($this->apiKey !== null) {
            return $this->apiKey->hasScope($scope);
        }

        $scopes = $this->application->scopes ?? ApiScopes::legacyDefaults();

        return ApiScopes::hasScope(is_array($scopes) ? $scopes : [], $scope, $this->isLegacy());
    }

    public function wantsAsyncPayments(Request $request): bool
    {
        if (filter_var($request->header('X-Async'), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        if ($this->apiKey !== null) {
            return (bool) $this->apiKey->async_payments;
        }

        return (bool) ($this->application->async_payments ?? false);
    }

    public function rateLimitTier(): string
    {
        if ($this->apiKey !== null) {
            return (string) ($this->apiKey->rate_limit_tier ?? 'standard');
        }

        return (string) ($this->application->rate_limit_tier ?? 'legacy');
    }
}
