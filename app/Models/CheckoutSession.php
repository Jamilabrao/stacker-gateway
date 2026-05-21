<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class CheckoutSession extends Model
{
    /** Query/body keys gravadas na sessão, no pedido (metadata) e enviadas à UTMfy. */
    public const TRACKING_FIELD_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'sck',
        'src',
    ];

    public const STEP_VISIT = 'visit';

    public const STEP_FORM_STARTED = 'form_started';

    public const STEP_FORM_FILLED = 'form_filled';

    public const STEP_CONVERTED = 'converted';

    protected $fillable = [
        'tenant_id', 'product_id', 'product_offer_id', 'subscription_plan_id',
        'checkout_slug', 'session_token', 'step', 'email', 'name', 'cpf', 'phone',
        'customer_ip', 'order_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'sck', 'src',
        'abandoned_webhook_fired_at',
    ];

    protected function casts(): array
    {
        return [
            'abandoned_webhook_fired_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productOffer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }

    /**
     * @return array<string, string|null>
     */
    public static function trackingFromQuery(Request $request): array
    {
        $out = [];
        foreach (self::TRACKING_FIELD_KEYS as $k) {
            $v = $request->query($k);
            $out[$k] = is_string($v) && trim($v) !== '' ? trim($v) : null;
        }

        return $out;
    }

    /** Colunas para `with(['checkoutSession:…'])` em pedidos. */
    public static function eagerSelectForOrderRelation(): string
    {
        return 'id,order_id,'.implode(',', self::TRACKING_FIELD_KEYS);
    }
}
