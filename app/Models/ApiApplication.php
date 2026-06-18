<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\ApiScopes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApiApplication extends Model
{
    /** Uma única aplicação API PIX por infoprodutor (slug fixo). */
    public const SLUG_GLOBAL_PIX_API = 'pix-global';

    /** @var list<string> */
    protected $hidden = [
        'secret_encrypted',
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'logo',
        'checkout_sidebar_bg',
        'api_key_hash',
        'public_key',
        'secret_key_hash',
        'secret_encrypted',
        'payment_gateways',
        'allowed_ips',
        'webhook_url',
        'default_return_url',
        'webhook_secret',
        'webhook_events',
        'webhook_enabled',
        'is_active',
        'is_legacy',
        'scopes',
        'strict_idempotency',
        'async_payments',
        'rate_limit_tier',
        'legacy_api_key_sha256',
    ];

    protected function casts(): array
    {
        return [
            'payment_gateways' => 'array',
            'allowed_ips' => 'array',
            'webhook_events' => 'array',
            'webhook_enabled' => 'boolean',
            'scopes' => 'array',
            'is_active' => 'boolean',
            'is_legacy' => 'boolean',
            'strict_idempotency' => 'boolean',
            'async_payments' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if ($model->is_legacy === null) {
                $model->is_legacy = true;
            }
            if ($model->scopes === null) {
                $model->scopes = ApiScopes::legacyDefaults();
            }
            if ($model->rate_limit_tier === null) {
                $model->rate_limit_tier = 'legacy';
            }
        });
    }

    /**
     * Default payment_gateways structure (same as Product checkout_config).
     *
     * @return array<string, mixed>
     */
    public static function defaultPaymentGateways(): array
    {
        return [
            'pix' => null,
            'pix_redundancy' => [],
            'card' => null,
            'card_redundancy' => [],
            'boleto' => null,
            'boleto_redundancy' => [],
            'pix_auto' => null,
            'pix_auto_redundancy' => [],
            'crypto' => null,
            'crypto_redundancy' => [],
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Verify that the given plain API key matches the stored hash.
     */
    public function verifyApiKey(string $plainKey): bool
    {
        return is_string($this->api_key_hash)
            && $this->api_key_hash !== ''
            && password_verify($plainKey, $this->api_key_hash);
    }

    /**
     * Hash an API key for storage. Use this when creating/regenerating keys.
     */
    public static function hashApiKey(string $plainKey): string
    {
        return password_hash($plainKey, PASSWORD_DEFAULT);
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

    public static function encryptSecretForStorage(string $plainSecret): string
    {
        return Crypt::encryptString($plainSecret);
    }

    public static function generatePublicKey(): string
    {
        $table = (new static)->getTable();
        do {
            $key = 'gpk_' . Str::lower(Str::random(40));
            if (! Schema::hasColumn($table, 'public_key')) {
                return $key;
            }
        } while (static::query()->where('public_key', $key)->exists());

        return $key;
    }

    public static function generateSecretKey(): string
    {
        return 'gsk_' . Str::random(12) . '_' . Str::random(32);
    }

    /**
     * Check if the given IP is allowed (empty allowed_ips = all allowed).
     */
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

    public function apiCheckoutSessions(): HasMany
    {
        return $this->hasMany(ApiCheckoutSession::class, 'api_application_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'api_application_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'api_application_id');
    }

    public function isLegacyApplication(): bool
    {
        return (bool) ($this->is_legacy ?? true);
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? ApiScopes::legacyDefaults();

        return ApiScopes::hasScope(is_array($scopes) ? $scopes : [], $scope, $this->isLegacyApplication());
    }

    /**
     * Generate a unique slug for the tenant.
     */
    public static function generateUniqueSlug(?int $tenantId, string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'app';
        }
        $slug = $base;
        $n = 0;
        while (static::forTenant($tenantId)->where('slug', $slug)->exists()) {
            $n++;
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /**
     * Garante a aplicação API PIX única do tenant (uma chave para todas as integrações).
     *
     * @param  array<string, string>|null  $keyReveal  Preenchido só na criação (public_key, secret_key).
     */
    public static function ensureDefaultPixApplication(int $tenantId, ?array &$keyReveal = null): self
    {
        $existing = static::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', self::SLUG_GLOBAL_PIX_API)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $plainKey = 'getfy_' . Str::random(12) . '_' . Str::random(32);
        $publicKey = static::generatePublicKey();
        $secretKey = static::generateSecretKey();

        $keyReveal = [
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
        ];

        $row = [
            'tenant_id' => $tenantId,
            'name' => 'Integração API PIX',
            'slug' => self::SLUG_GLOBAL_PIX_API,
            'api_key_hash' => static::hashApiKey($plainKey),
            'public_key' => $publicKey,
            'secret_key_hash' => static::hashSecretKey($secretKey),
            'payment_gateways' => static::defaultPaymentGateways(),
            'allowed_ips' => [],
            'webhook_url' => null,
            'default_return_url' => null,
            'webhook_secret' => null,
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
            'strict_idempotency' => false,
            'async_payments' => false,
            'rate_limit_tier' => 'legacy',
            'checkout_sidebar_bg' => null,
        ];
        if (Schema::hasColumn((new static)->getTable(), 'secret_encrypted')) {
            $row['secret_encrypted'] = static::encryptSecretForStorage($secretKey);
        }

        return static::create($row);
    }

    public function isGlobalPixApplication(): bool
    {
        return $this->slug === self::SLUG_GLOBAL_PIX_API;
    }
}
