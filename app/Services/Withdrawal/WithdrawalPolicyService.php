<?php

namespace App\Services\Withdrawal;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class WithdrawalPolicyService
{
    public static function autoWithdrawalEnabled(): bool
    {
        $v = Setting::get('platform_auto_withdrawal_enabled', true, null);

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function hoursEnabled(): bool
    {
        $v = Setting::get('platform_withdrawal_hours_enabled', false, null);

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function timezone(): string
    {
        $tz = Setting::get('platform_withdrawal_timezone', 'America/Sao_Paulo', null);

        return is_string($tz) && $tz !== '' ? $tz : 'America/Sao_Paulo';
    }

    public static function hoursStart(): string
    {
        $v = Setting::get('platform_withdrawal_hours_start', '06:00', null);

        return is_string($v) && $v !== '' ? $v : '06:00';
    }

    public static function hoursEnd(): string
    {
        $v = Setting::get('platform_withdrawal_hours_end', '21:00', null);

        return is_string($v) && $v !== '' ? $v : '21:00';
    }

    public static function allowsRequestAt(?Carbon $at = null): bool
    {
        if (! self::hoursEnabled()) {
            return true;
        }

        $at = ($at ?? now())->timezone(self::timezone());
        $start = self::parseTimeOnDate($at, self::hoursStart());
        $end = self::parseTimeOnDate($at, self::hoursEnd());

        if ($start->lte($end)) {
            return $at->gte($start) && $at->lte($end);
        }

        return $at->gte($start) || $at->lte($end);
    }

    public static function requestBlockedMessage(): string
    {
        return 'Saques permitidos das '
            .self::hoursStart().' às '.self::hoursEnd()
            .' ('.self::timezone().').';
    }

    public static function requiresManualApprovalPin(): bool
    {
        return ! self::autoWithdrawalEnabled();
    }

    public static function hasManualApprovalPin(): bool
    {
        $hash = Setting::get('platform_manual_approval_pin_hash', null, null);

        return is_string($hash) && $hash !== '';
    }

    public static function verifyManualApprovalPin(string $pin): bool
    {
        $hash = Setting::get('platform_manual_approval_pin_hash', null, null);
        if (! is_string($hash) || $hash === '') {
            return false;
        }

        return Hash::check($pin, $hash);
    }

    public static function setManualApprovalPin(string $pin): void
    {
        Setting::set('platform_manual_approval_pin_hash', Hash::make($pin), null);
    }

    public static function changeManualApprovalPin(string $currentPin, string $newPin): void
    {
        if (! self::verifyManualApprovalPin($currentPin)) {
            throw ValidationException::withMessages([
                'current_manual_approval_pin' => 'PIN atual incorreto.',
            ]);
        }

        self::setManualApprovalPin($newPin);
    }

    public static function generateResetPin(): string
    {
        $pin = '';
        for ($i = 0; $i < 8; $i++) {
            $pin .= (string) random_int(0, 9);
        }

        return $pin;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toFrontendProps(): array
    {
        return [
            'auto_withdrawal_enabled' => self::autoWithdrawalEnabled(),
            'hours_enabled' => self::hoursEnabled(),
            'hours_start' => self::hoursStart(),
            'hours_end' => self::hoursEnd(),
            'timezone' => self::timezone(),
            'has_manual_approval_pin' => self::hasManualApprovalPin(),
        ];
    }

    private static function parseTimeOnDate(Carbon $date, string $time): Carbon
    {
        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return $date->copy()->setTime($hour, $minute, 0);
    }
}
