<?php

namespace App\Models;

use App\Support\ApiScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = [
        'tenant_id',
        'api_application_id',
        'name',
        'public_key',
        'secret_key_hash',
        'secret_encrypted',
        'scopes',
        'allowed_ips',
        'strict_idempotency',
        'async_payments',
        'rate_limit_tier',
        'is_active',
        'last_used_at',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'strict_idempotency' => 'boolean',
            'async_payments' => 'boolean',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function apiApplication(): BelongsTo
    {
        return $this->belongsTo(ApiApplication::class, 'api_application_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function verifySecretKey(string $plainKey): bool
    {
        return is_string($this->secret_key_hash)
            && $this->secret_key_hash !== ''
            && password_verify($plainKey, $this->secret_key_hash);
    }

    public static function hashSecretKey(string $plainKey): string
    {
        return password_hash($plainKey, PASSWORD_DEFAULT);
    }

    public static function generatePublicKey(): string
    {
        do {
            $key = 'gpk_' . Str::lower(Str::random(40));
        } while (static::query()->where('public_key', $key)->exists());

        return $key;
    }

    public static function generateSecretKey(): string
    {
        return 'gsk_' . Str::random(12) . '_' . Str::random(32);
    }

    public static function encryptSecretForStorage(string $plainSecret): string
    {
        return Crypt::encryptString($plainSecret);
    }

    public function canRevealSecret(): bool
    {
        if (! Schema::hasColumn($this->getTable(), 'secret_encrypted')) {
            return false;
        }

        return is_string($this->secret_encrypted) && $this->secret_encrypted !== '';
    }

    public function isIpAllowed(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return true;
        }
        $allowed = $this->allowed_ips;
        if (! is_array($allowed) || count($allowed) === 0) {
            return true;
        }

        return in_array($ip, $allowed, true);
    }

    public function hasScope(string $scope): bool
    {
        return ApiScopes::hasScope($this->scopes ?? [], $scope, false);
    }

    public function touchLastUsed(): void
    {
        if (Schema::hasColumn($this->getTable(), 'last_used_at')) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
