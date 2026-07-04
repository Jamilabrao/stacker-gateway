<?php

namespace App\Support;

use App\Models\PlatformIntegraxSetting;
use InvalidArgumentException;

class IntegraxCartRecoverySteps
{
    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function defaults(): array
    {
        $message = (string) (config('integrax.defaults.messages.cart_recovery') ?? 'Oi {nome}! Seu carrinho de {produto} te espera: {link}');

        return [
            ['delay_minutes' => 10, 'message' => $message],
            ['delay_minutes' => 1440, 'message' => $message],
            ['delay_minutes' => 2880, 'message' => $message],
        ];
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function forSetting(PlatformIntegraxSetting $settings): array
    {
        $raw = $settings->cart_recovery_steps;
        if (is_array($raw) && $raw !== []) {
            return self::normalizeStored($raw);
        }

        return self::defaults();
    }

    /**
     * @return array<int, array{delay_value: int, delay_unit: string, message: string}>
     */
    public static function toUiSteps(PlatformIntegraxSetting $settings): array
    {
        return array_map(
            fn (array $step) => self::toUiStep($step),
            self::forSetting($settings)
        );
    }

    /**
     * @param  array<int, array{delay_value?: mixed, delay_unit?: mixed, message?: mixed}>  $uiSteps
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function fromUiInput(array $uiSteps): array
    {
        $steps = [];

        foreach ($uiSteps as $uiStep) {
            if (! is_array($uiStep)) {
                continue;
            }

            $message = trim((string) ($uiStep['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $value = max(1, (int) ($uiStep['delay_value'] ?? 1));
            $unit = (string) ($uiStep['delay_unit'] ?? 'minutes');
            $delayMinutes = self::toDelayMinutes($value, $unit);

            if ($delayMinutes > self::maxDelayMinutes()) {
                throw new InvalidArgumentException('Cada mensagem deve ser agendada em no máximo '.self::maxDelayMinutesLabel().'.');
            }

            $steps[] = [
                'delay_minutes' => $delayMinutes,
                'message' => $message,
            ];
        }

        return self::normalizeStored($steps);
    }

    /**
     * @param  array<int, array{delay_minutes?: mixed, message?: mixed}>  $steps
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function normalizeStored(array $steps): array
    {
        $normalized = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $message = trim((string) ($step['message'] ?? ''));
            $delayMinutes = max(1, (int) ($step['delay_minutes'] ?? 0));

            if ($message === '' || $delayMinutes < 1) {
                continue;
            }

            if (mb_strlen($message) > (int) config('integrax.max_message_length', 160)) {
                throw new InvalidArgumentException('Cada mensagem de recuperação deve ter no máximo 160 caracteres.');
            }

            $normalized[] = [
                'delay_minutes' => $delayMinutes,
                'message' => $message,
            ];
        }

        $previousDelay = 0;
        foreach ($normalized as $step) {
            if ($step['delay_minutes'] <= $previousDelay) {
                throw new InvalidArgumentException('Os tempos de envio devem ser crescentes (cada mensagem após a anterior).');
            }
            $previousDelay = $step['delay_minutes'];
        }

        if (count($normalized) > 10) {
            throw new InvalidArgumentException('Máximo de 10 mensagens de recuperação de carrinho.');
        }

        return array_values($normalized);
    }

    /**
     * @return array{delay_value: int, delay_unit: string, message: string}
     */
    private static function toUiStep(array $step): array
    {
        $delayMinutes = max(1, (int) ($step['delay_minutes'] ?? 1));
        $message = (string) ($step['message'] ?? '');

        if ($delayMinutes % 1440 === 0) {
            return [
                'delay_value' => (int) ($delayMinutes / 1440),
                'delay_unit' => 'days',
                'message' => $message,
            ];
        }

        if ($delayMinutes % 60 === 0) {
            return [
                'delay_value' => (int) ($delayMinutes / 60),
                'delay_unit' => 'hours',
                'message' => $message,
            ];
        }

        return [
            'delay_value' => $delayMinutes,
            'delay_unit' => 'minutes',
            'message' => $message,
        ];
    }

    private static function toDelayMinutes(int $value, string $unit): int
    {
        return match ($unit) {
            'hours' => $value * 60,
            'days' => $value * 1440,
            default => $value,
        };
    }

    public static function maxDelayMinutes(): int
    {
        return 43200; // 30 dias
    }

    public static function maxDelayMinutesLabel(): string
    {
        return '30 dias';
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    private static function fromLegacySetting(PlatformIntegraxSetting $settings): array
    {
        $message = trim((string) ($settings->message_cart_recovery ?? ''));
        if ($message === '') {
            $message = (string) (config('integrax.defaults.messages.cart_recovery') ?? '');
        }

        $firstDelay = max(1, (int) ($settings->cart_first_delay_minutes ?? 10));
        $interval = max(30, (int) ($settings->cart_interval_minutes ?? 1440));
        $maxDurationMinutes = max(60, (int) ($settings->cart_max_duration_hours ?? 72) * 60);
        $maxSends = max(1, min(10, (int) ($settings->cart_max_sends ?? 3)));

        $steps = [];
        for ($i = 0; $i < $maxSends; $i++) {
            $delay = $firstDelay + ($i * $interval);
            if ($delay > $maxDurationMinutes) {
                break;
            }

            $steps[] = [
                'delay_minutes' => $delay,
                'message' => $message,
            ];
        }

        if ($steps === []) {
            return self::defaults();
        }

        return self::normalizeStored($steps);
    }
}
