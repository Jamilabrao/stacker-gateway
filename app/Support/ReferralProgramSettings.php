<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Programa Indique e Ganhe (indicação seller→seller) — config global da plataforma.
 */
final class ReferralProgramSettings
{
    public const KEY_ENABLED = 'referral_program_enabled';

    public const KEY_COMMISSION_PERCENT = 'referral_commission_percent';

    public const KEY_ELIGIBILITY_DAYS = 'referral_eligibility_days';

    public const KEY_RULES = 'referral_rules_html';

    public const KEY_MIN_WITHDRAWAL = 'referral_min_withdrawal';

    public const KEY_COOKIE_DAYS = 'referral_cookie_days';

    public const COOKIE_NAME = 'referral_ref';

    public static function isEnabled(): bool
    {
        $value = Setting::get(self::KEY_ENABLED, '0', null);

        return $value === '1'
            || $value === 1
            || $value === true
            || $value === 'true';
    }

    public static function commissionPercent(): float
    {
        $raw = Setting::get(self::KEY_COMMISSION_PERCENT, '20', null);
        $value = is_numeric($raw) ? (float) $raw : 20.0;

        return max(0.0, min(100.0, round($value, 4)));
    }

    /**
     * Taxa efetiva do indicador: override do usuário, senão padrão da plataforma.
     */
    public static function commissionPercentForReferrer(?\App\Models\User $referrer): float
    {
        if ($referrer !== null
            && \Illuminate\Support\Facades\Schema::hasColumn('users', 'referral_commission_percent')
            && $referrer->referral_commission_percent !== null
            && $referrer->referral_commission_percent !== '') {
            $value = (float) $referrer->referral_commission_percent;

            return max(0.0, min(100.0, round($value, 4)));
        }

        return self::commissionPercent();
    }

    /**
     * Dias a partir de referred_at em que o indicador ainda ganha comissão.
     * 0 = vitalício.
     */
    public static function eligibilityDays(): int
    {
        $raw = Setting::get(self::KEY_ELIGIBILITY_DAYS, '365', null);
        $value = is_numeric($raw) ? (int) $raw : 365;

        return max(0, $value);
    }

    public static function rulesHtml(): string
    {
        return (string) Setting::get(self::KEY_RULES, '', null);
    }

    public static function minWithdrawal(): float
    {
        $raw = Setting::get(self::KEY_MIN_WITHDRAWAL, '50', null);
        $value = is_numeric($raw) ? (float) $raw : 50.0;

        return max(0.01, round($value, 2));
    }

    public static function cookieDays(): int
    {
        $raw = Setting::get(self::KEY_COOKIE_DAYS, '30', null);
        $value = is_numeric($raw) ? (int) $raw : 30;

        return max(1, min(365, $value));
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            'enabled' => self::isEnabled(),
            'commission_percent' => self::commissionPercent(),
            'eligibility_days' => self::eligibilityDays(),
            'rules_html' => self::rulesHtml(),
            'min_withdrawal' => self::minWithdrawal(),
            'cookie_days' => self::cookieDays(),
        ];
    }

    /**
     * Props públicas para o painel seller (Inertia share).
     *
     * @return array{enabled: bool, commission_percent: float, eligibility_days: int, min_withdrawal: float}
     */
    public static function publicConfig(): array
    {
        if (! self::isEnabled()) {
            return [
                'enabled' => false,
                'commission_percent' => 0.0,
                'eligibility_days' => 0,
                'min_withdrawal' => self::minWithdrawal(),
            ];
        }

        return [
            'enabled' => true,
            'commission_percent' => self::commissionPercent(),
            'eligibility_days' => self::eligibilityDays(),
            'min_withdrawal' => self::minWithdrawal(),
        ];
    }

    /**
     * @param  array{
     *   enabled?: bool|string|int,
     *   commission_percent?: float|int|string,
     *   eligibility_days?: int|string,
     *   rules_html?: string,
     *   min_withdrawal?: float|int|string,
     *   cookie_days?: int|string
     * }  $data
     */
    public static function persistFromValidated(array $data): void
    {
        $enabled = ! empty($data['enabled'])
            && $data['enabled'] !== '0'
            && $data['enabled'] !== false
            && $data['enabled'] !== 0;

        Setting::set(self::KEY_ENABLED, $enabled ? '1' : '0', null);

        if (array_key_exists('commission_percent', $data)) {
            $pct = is_numeric($data['commission_percent']) ? (float) $data['commission_percent'] : 20.0;
            Setting::set(self::KEY_COMMISSION_PERCENT, (string) max(0, min(100, round($pct, 4))), null);
        }

        if (array_key_exists('eligibility_days', $data)) {
            $days = is_numeric($data['eligibility_days']) ? (int) $data['eligibility_days'] : 365;
            Setting::set(self::KEY_ELIGIBILITY_DAYS, (string) max(0, $days), null);
        }

        if (array_key_exists('rules_html', $data)) {
            $rules = HtmlSanitizer::plainTextMultiline((string) ($data['rules_html'] ?? ''), 20000);
            Setting::set(self::KEY_RULES, $rules, null);
        }

        if (array_key_exists('min_withdrawal', $data)) {
            $min = is_numeric($data['min_withdrawal']) ? (float) $data['min_withdrawal'] : 50.0;
            Setting::set(self::KEY_MIN_WITHDRAWAL, (string) max(0.01, round($min, 2)), null);
        }

        if (array_key_exists('cookie_days', $data)) {
            $cookieDays = is_numeric($data['cookie_days']) ? (int) $data['cookie_days'] : 30;
            Setting::set(self::KEY_COOKIE_DAYS, (string) max(1, min(365, $cookieDays)), null);
        }
    }
}
