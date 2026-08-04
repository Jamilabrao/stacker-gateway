<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricsEvent extends Model
{
    public const PAGE_VIEW = 'page_view';

    public const CHECKOUT_VIEW = 'checkout_view';

    public const CHECKOUT_STARTED = 'checkout_started';

    public const PIX_CREATED = 'pix_created';

    public const PAYMENT_APPROVED = 'payment_approved';

    public const PAYMENT_REFUSED = 'payment_refused';

    public const PAYMENT_REFUNDED = 'payment_refunded';

    public const CHARGEBACK_RECEIVED = 'chargeback_received';

    public const MED_RECEIVED = 'med_received';

    public const LINK_CLICKED = 'link_clicked';

    public const PAYMENT_PENDING = 'payment_pending';

    public const PAYMENT_CANCELLED = 'payment_cancelled';

    protected $table = 'metrics_events';

    protected $fillable = [
        'event_id',
        'event_name',
        'metrics_session_id',
        'session_key',
        'visitor_key',
        'tenant_id',
        'product_id',
        'offer_id',
        'plan_id',
        'order_id',
        'checkout_session_id',
        'affiliate_user_id',
        'affiliate_ref',
        'coproducer_user_id',
        'campaign_code',
        'destination_url',
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
        'latitude',
        'longitude',
        'isp',
        'timezone',
        'conversion_status',
        'amount',
        'currency',
        'seconds_to_convert',
        'geo_enriched',
        'properties',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'tracking_params' => 'array',
            'properties' => 'array',
            'geo_enriched' => 'boolean',
            'occurred_at' => 'datetime',
            'amount' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'seconds_to_convert' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(MetricsSession::class, 'metrics_session_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
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
