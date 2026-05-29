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

    /** Janela após interação no checkout para contar abandono (relatórios) e disparar webhook. */
    public const ABANDONMENT_GRACE_MINUTES = 10;

    protected $fillable = [
        'tenant_id', 'product_id', 'product_offer_id', 'subscription_plan_id',
        'checkout_slug', 'session_token', 'step', 'form_started_at', 'form_filled_at',
        'email', 'name', 'cpf', 'phone',
        'customer_ip', 'order_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'sck', 'src',
        'abandoned_webhook_fired_at',
    ];

    protected function casts(): array
    {
        return [
            'form_started_at' => 'datetime',
            'form_filled_at' => 'datetime',
            'abandoned_webhook_fired_at' => 'datetime',
        ];
    }

    public static function abandonmentEligibilityCutoff(): \Illuminate\Support\Carbon
    {
        return now()->subMinutes(self::ABANDONMENT_GRACE_MINUTES);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>
     */
    public function scopeWhereAbandonmentVisitEligible($query)
    {
        return $query
            ->where('step', self::STEP_VISIT)
            ->whereNull('order_id')
            ->where('created_at', '<=', self::abandonmentEligibilityCutoff());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>
     */
    public function scopeWhereAbandonmentFormEligible($query)
    {
        $cutoff = self::abandonmentEligibilityCutoff();

        return $query
            ->whereIn('step', [self::STEP_FORM_STARTED, self::STEP_FORM_FILLED])
            ->whereNull('order_id')
            ->whereRaw(
                'COALESCE(form_filled_at, form_started_at, updated_at, created_at) <= ?',
                [$cutoff]
            );
    }

    /**
     * Sessão com venda efetivamente aprovada (pedido completed), não apenas checkout iniciado/pendente.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>
     */
    public function scopeWhereFunnelConversionCompleted($query)
    {
        return $query->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', 'completed'));
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
