<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetricsSession extends Model
{
    protected $table = 'metrics_sessions';

    protected $fillable = [
        'session_key',
        'visitor_key',
        'tenant_id',
        'product_id',
        'offer_id',
        'plan_id',
        'affiliate_user_id',
        'affiliate_ref',
        'coproducer_user_id',
        'campaign_code',
        'landing_url',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'gclid',
        'ttclid',
        'src',
        'sck',
        'subid',
        'subid2',
        'subid3',
        'tracking_params',
        'device_type',
        'os_name',
        'browser_name',
        'user_agent',
        'ip_hash',
        'ip_masked',
        'country',
        'region',
        'city',
        'first_touch_at',
        'last_touch_at',
        'converted_at',
        'events_count',
        'clicks_count',
    ];

    protected function casts(): array
    {
        return [
            'tracking_params' => 'array',
            'first_touch_at' => 'datetime',
            'last_touch_at' => 'datetime',
            'converted_at' => 'datetime',
            'offer_id' => 'integer',
            'plan_id' => 'integer',
            'affiliate_user_id' => 'integer',
            'coproducer_user_id' => 'integer',
            'events_count' => 'integer',
            'clicks_count' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(MetricsEvent::class, 'metrics_session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }
}
