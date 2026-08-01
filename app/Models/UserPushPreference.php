<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPushPreference extends Model
{
    protected $fillable = [
        'user_id',
        'sale_approved',
        'pix_generated',
        'boleto_generated',
        'withdrawal_paid',
        'affiliate_sale_approved',
        'affiliate_enrollment_approved',
        'daily_summary',
        'system',
        'show_product_name',
        'show_sale_amount',
        'show_payment_method',
    ];

    protected function casts(): array
    {
        return [
            'sale_approved' => 'boolean',
            'pix_generated' => 'boolean',
            'boleto_generated' => 'boolean',
            'withdrawal_paid' => 'boolean',
            'affiliate_sale_approved' => 'boolean',
            'affiliate_enrollment_approved' => 'boolean',
            'daily_summary' => 'boolean',
            'system' => 'boolean',
            'show_product_name' => 'boolean',
            'show_sale_amount' => 'boolean',
            'show_payment_method' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Defaults alinhados ao comportamento histórico (tudo ligado).
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            'sale_approved' => true,
            'pix_generated' => true,
            'boleto_generated' => true,
            'withdrawal_paid' => true,
            'affiliate_sale_approved' => true,
            'affiliate_enrollment_approved' => true,
            'daily_summary' => true,
            'system' => true,
            'show_product_name' => true,
            'show_sale_amount' => true,
            'show_payment_method' => true,
        ];
    }
}
