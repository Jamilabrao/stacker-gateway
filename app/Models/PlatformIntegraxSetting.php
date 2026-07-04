<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformIntegraxSetting extends Model
{
    public const EVENT_CART_RECOVERY = 'cart_recovery';

    public const EVENT_ORDER_PAID = 'order_paid';

    public const EVENT_ACCESS_GRANTED = 'access_granted';

    public const EVENT_PIX_GENERATED = 'pix_generated';

    protected $fillable = [
        'is_active',
        'sms_checkout_only',
        'api_token',
        'sender_from',
        'event_cart_recovery_enabled',
        'event_order_paid_enabled',
        'event_access_granted_enabled',
        'event_pix_generated_enabled',
        'message_cart_recovery',
        'message_order_paid',
        'message_access_granted',
        'message_pix_generated',
        'cart_recovery_steps',
        'cart_first_delay_minutes',
        'cart_interval_minutes',
        'cart_max_duration_hours',
        'cart_max_sends',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sms_checkout_only' => 'boolean',
            'api_token' => 'encrypted',
            'event_cart_recovery_enabled' => 'boolean',
            'event_order_paid_enabled' => 'boolean',
            'event_access_granted_enabled' => 'boolean',
            'event_pix_generated_enabled' => 'boolean',
            'cart_recovery_steps' => 'array',
            'cart_first_delay_minutes' => 'integer',
            'cart_interval_minutes' => 'integer',
            'cart_max_duration_hours' => 'integer',
            'cart_max_sends' => 'integer',
        ];
    }

    public static function instance(): self
    {
        $defaults = config('integrax.defaults', []);
        $defaultSender = (string) ($defaults['sender_from'] ?? '29094');

        $settings = static::query()->firstOrCreate([], [
            'is_active' => false,
            'sms_checkout_only' => true,
            'sender_from' => $defaultSender,
            'event_cart_recovery_enabled' => false,
            'event_order_paid_enabled' => false,
            'event_access_granted_enabled' => false,
            'event_pix_generated_enabled' => false,
            'message_cart_recovery' => $defaults['messages']['cart_recovery'] ?? null,
            'message_order_paid' => $defaults['messages']['order_paid'] ?? null,
            'message_access_granted' => $defaults['messages']['access_granted'] ?? null,
            'message_pix_generated' => $defaults['messages']['pix_generated'] ?? null,
            'cart_recovery_steps' => \App\Support\IntegraxCartRecoverySteps::defaults(),
            'cart_first_delay_minutes' => (int) ($defaults['cart_first_delay_minutes'] ?? 10),
            'cart_interval_minutes' => (int) ($defaults['cart_interval_minutes'] ?? 1440),
            'cart_max_duration_hours' => (int) ($defaults['cart_max_duration_hours'] ?? 72),
            'cart_max_sends' => (int) ($defaults['cart_max_sends'] ?? 3),
        ]);

        if (! is_string($settings->sender_from) || trim($settings->sender_from) === '') {
            $settings->sender_from = $defaultSender;
            $settings->save();
        }

        return $settings;
    }

    public function isConfigured(): bool
    {
        return $this->is_active
            && is_string($this->api_token) && trim($this->api_token) !== ''
            && is_string($this->sender_from) && trim($this->sender_from) !== '';
    }

    public function isEventEnabled(string $eventType): bool
    {
        return match ($eventType) {
            self::EVENT_CART_RECOVERY => (bool) $this->event_cart_recovery_enabled,
            self::EVENT_ORDER_PAID => (bool) $this->event_order_paid_enabled,
            self::EVENT_ACCESS_GRANTED => (bool) $this->event_access_granted_enabled,
            self::EVENT_PIX_GENERATED => (bool) $this->event_pix_generated_enabled,
            default => false,
        };
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public function cartRecoverySteps(): array
    {
        return \App\Support\IntegraxCartRecoverySteps::forSetting($this);
    }

    public function messageTemplateFor(string $eventType): ?string
    {
        return match ($eventType) {
            self::EVENT_CART_RECOVERY => $this->message_cart_recovery,
            self::EVENT_ORDER_PAID => $this->message_order_paid,
            self::EVENT_ACCESS_GRANTED => $this->message_access_granted,
            self::EVENT_PIX_GENERATED => $this->message_pix_generated,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'is_active' => $this->is_active,
            'sms_checkout_only' => $this->sms_checkout_only ?? true,
            'configured' => $this->api_token !== null && $this->api_token !== '',
            'sender_from' => $this->sender_from ?: (string) (config('integrax.defaults.sender_from') ?? '29094'),
            'event_cart_recovery_enabled' => $this->event_cart_recovery_enabled,
            'event_order_paid_enabled' => $this->event_order_paid_enabled,
            'event_access_granted_enabled' => $this->event_access_granted_enabled,
            'event_pix_generated_enabled' => $this->event_pix_generated_enabled,
            'message_cart_recovery' => $this->message_cart_recovery ?? '',
            'message_order_paid' => $this->message_order_paid ?? '',
            'message_access_granted' => $this->message_access_granted ?? '',
            'message_pix_generated' => $this->message_pix_generated ?? '',
            'cart_recovery_steps' => \App\Support\IntegraxCartRecoverySteps::toUiSteps($this),
            'cart_first_delay_minutes' => $this->cart_first_delay_minutes,
            'cart_interval_minutes' => $this->cart_interval_minutes,
            'cart_interval_hours' => max(1, (int) round(((int) $this->cart_interval_minutes) / 60)),
            'cart_max_duration_hours' => $this->cart_max_duration_hours,
            'cart_max_duration_days' => max(1, (int) round(((int) $this->cart_max_duration_hours) / 24)),
            'cart_max_sends' => $this->cart_max_sends,
            'register_url' => config('integrax.register_url'),
            'support_whatsapp' => config('integrax.support_whatsapp'),
        ];
    }
}
