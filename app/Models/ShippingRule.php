<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRule extends Model
{
    public const MATCH_ALL = 'all';

    public const MATCH_STATE = 'state';

    public const MATCH_CITY = 'city';

    public const MATCH_CEP_RANGE = 'cep_range';

    public const MATCH_CEP_PREFIX = 'cep_prefix';

    protected $fillable = [
        'shipping_store_id',
        'priority',
        'name',
        'is_active',
        'match_type',
        'match_config',
        'price',
        'is_free',
        'delivery_days_min',
        'delivery_days_max',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'match_config' => 'array',
            'price' => 'decimal:2',
            'is_free' => 'boolean',
            'delivery_days_min' => 'integer',
            'delivery_days_max' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShippingStore::class, 'shipping_store_id');
    }

    /**
     * @return list<string>
     */
    public static function matchTypes(): array
    {
        return [
            self::MATCH_ALL,
            self::MATCH_STATE,
            self::MATCH_CITY,
            self::MATCH_CEP_RANGE,
            self::MATCH_CEP_PREFIX,
        ];
    }
}
