<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelPushCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIALLY_SENT = 'partially_sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const MODE_NOW = 'now';

    public const MODE_SCHEDULED = 'scheduled';

    public const AUDIENCE_ALL_SUBSCRIBERS = 'all_subscribers';

    public const AUDIENCE_ALL_MERCHANTS = 'all_merchants';

    public const AUDIENCE_SELECTED = 'selected';

    public const AUDIENCE_ACTIVE_MERCHANTS = 'active_merchants';

    public const AUDIENCE_WITH_SALES = 'with_sales';

    public const AUDIENCE_WITHOUT_SALES = 'without_sales';

    public const AUDIENCE_ACCOUNT_MANAGER = 'account_manager';

    protected $fillable = [
        'title',
        'body',
        'image_url',
        'target_url',
        'audience',
        'audience_filters',
        'send_mode',
        'scheduled_at',
        'timezone',
        'silent',
        'status',
        'idempotency_key',
        'eligible_count',
        'sent_count',
        'failed_count',
        'invalid_count',
        'expired_count',
        'created_by',
        'processing_started_at',
        'completed_at',
        'cancelled_at',
        'last_error',
        'result_meta',
    ];

    protected function casts(): array
    {
        return [
            'audience_filters' => 'array',
            'result_meta' => 'array',
            'scheduled_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'silent' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Rascunho',
            self::STATUS_SCHEDULED => 'Agendada',
            self::STATUS_PROCESSING => 'Processando',
            self::STATUS_SENT => 'Enviada',
            self::STATUS_PARTIALLY_SENT => 'Enviada parcialmente',
            self::STATUS_FAILED => 'Falhou',
            self::STATUS_CANCELLED => 'Cancelada',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function audienceLabels(): array
    {
        return [
            self::AUDIENCE_ALL_SUBSCRIBERS => 'Todos os inscritos no painel',
            self::AUDIENCE_ALL_MERCHANTS => 'Todos os infoprodutores inscritos',
            self::AUDIENCE_SELECTED => 'Infoprodutores selecionados',
            self::AUDIENCE_ACTIVE_MERCHANTS => 'Infoprodutores ativos',
            self::AUDIENCE_WITH_SALES => 'Com venda no período',
            self::AUDIENCE_WITHOUT_SALES => 'Sem venda no período',
            self::AUDIENCE_ACCOUNT_MANAGER => 'Gerente de conta específico',
        ];
    }
}
