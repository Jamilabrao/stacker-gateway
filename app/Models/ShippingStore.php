<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingStore extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
        'origin_zip',
        'origin_street',
        'origin_number',
        'origin_complement',
        'origin_neighborhood',
        'origin_city',
        'origin_state',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ShippingRule::class)->orderBy('priority');
    }

    public function activeRules(): HasMany
    {
        return $this->rules()->where('is_active', true);
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }

    public function originSummary(): string
    {
        $parts = array_filter([
            $this->origin_street,
            $this->origin_number,
            $this->origin_neighborhood,
            $this->origin_city,
            $this->origin_state,
        ]);

        return $parts !== [] ? implode(', ', $parts) : '—';
    }
}
