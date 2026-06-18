<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class CajuPayAccount extends Model
{
    protected $table = 'cajupay_accounts';

    protected $fillable = [
        'name',
        'is_default',
        'credentials',
        'is_connected',
        'is_enabled',
        'webhook_setup_status',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_connected' => 'boolean',
            'is_enabled' => 'boolean',
            'webhook_setup_status' => 'array',
        ];
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(User::class, 'cajupay_account_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public static function defaultAccount(): ?self
    {
        return static::query()->default()->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDecryptedCredentials(): array
    {
        if (empty($this->credentials)) {
            return [];
        }

        try {
            $decrypted = Crypt::decryptString($this->credentials);
            $decoded = json_decode($decrypted, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::warning('CajuPayAccount getDecryptedCredentials failed', [
                'account_id' => $this->id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function setEncryptedCredentials(array $credentials): void
    {
        $this->credentials = Crypt::encryptString(json_encode($credentials));
    }

    public function hasWebhookSigningSecret(): bool
    {
        $creds = $this->getDecryptedCredentials();

        foreach (['checkout_webhook_signing_secret', 'webhook_signing_secret'] as $key) {
            if (trim((string) ($creds[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminSummary(): array
    {
        $setup = is_array($this->webhook_setup_status) ? $this->webhook_setup_status : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'is_connected' => $this->is_connected,
            'is_enabled' => $this->is_enabled,
            'has_webhook_secret' => $this->hasWebhookSigningSecret(),
            'webhook_has_enabled_endpoint' => (bool) ($setup['has_enabled_endpoint'] ?? false),
            'webhook_subscribes_checkout_events' => (bool) ($setup['subscribes_checkout_events'] ?? false),
            'merchants_count' => $this->merchants()->count(),
        ];
    }
}
