<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\HtmlSanitizer;
use App\Support\LegalDocumentDefaults;

class LegalDocumentsService
{
    public const SETTING_PRIVACY_HTML = 'legal_privacy_policy_html';

    public const SETTING_TERMS_HTML = 'legal_terms_of_use_html';

    public const SETTING_PRIVACY_EMAIL = 'legal_privacy_contact_email';

    public const SETTING_COOKIE_BANNER = 'legal_cookie_banner_enabled';

    public const SETTING_PRIVACY_UPDATED_AT = 'legal_privacy_updated_at';

    public const SETTING_TERMS_UPDATED_AT = 'legal_terms_updated_at';

    public function privacyHtml(): string
    {
        $raw = Setting::get(self::SETTING_PRIVACY_HTML, null, null);
        $html = is_string($raw) && trim($raw) !== ''
            ? $raw
            : LegalDocumentDefaults::privacyPolicyHtml();

        return $this->replacePlaceholders($html);
    }

    public function termsHtml(): string
    {
        $raw = Setting::get(self::SETTING_TERMS_HTML, null, null);
        $html = is_string($raw) && trim($raw) !== ''
            ? $raw
            : LegalDocumentDefaults::termsOfUseHtml();

        return $this->replacePlaceholders($html);
    }

    public function privacyContactEmail(): string
    {
        $email = trim((string) Setting::get(self::SETTING_PRIVACY_EMAIL, '', null));

        return $email !== '' ? $email : LegalDocumentDefaults::defaultPrivacyContactPlaceholder();
    }

    public function cookieBannerEnabled(): bool
    {
        $raw = Setting::get(self::SETTING_COOKIE_BANNER, null, null);
        if ($raw === null || $raw === '') {
            return true;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public function contentVersion(): string
    {
        return hash('sha256', $this->privacyHtml().'|'.$this->termsHtml().'|'.$this->privacyContactEmail());
    }

    public function replacePlaceholders(string $html): string
    {
        return str_replace(
            '{{privacy_contact_email}}',
            e($this->privacyContactEmail()),
            $html
        );
    }

    public function sanitizedPrivacyHtml(): string
    {
        return HtmlSanitizer::sanitize($this->privacyHtml());
    }

    public function sanitizedTermsHtml(): string
    {
        return HtmlSanitizer::sanitize($this->termsHtml());
    }

    public function privacyUpdatedAt(): ?string
    {
        $v = Setting::get(self::SETTING_PRIVACY_UPDATED_AT, null, null);

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function termsUpdatedAt(): ?string
    {
        $v = Setting::get(self::SETTING_TERMS_UPDATED_AT, null, null);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function forAdminForm(): array
    {
        $privacyStored = Setting::get(self::SETTING_PRIVACY_HTML, null, null);
        $termsStored = Setting::get(self::SETTING_TERMS_HTML, null, null);

        return [
            'legal_privacy_policy_html' => is_string($privacyStored) && trim($privacyStored) !== ''
                ? $privacyStored
                : LegalDocumentDefaults::privacyPolicyHtml(),
            'legal_terms_of_use_html' => is_string($termsStored) && trim($termsStored) !== ''
                ? $termsStored
                : LegalDocumentDefaults::termsOfUseHtml(),
            'legal_privacy_contact_email' => (string) Setting::get(self::SETTING_PRIVACY_EMAIL, '', null),
            'legal_cookie_banner_enabled' => $this->cookieBannerEnabled(),
            'legal_privacy_updated_at' => $this->privacyUpdatedAt(),
            'legal_terms_updated_at' => $this->termsUpdatedAt(),
            'legal_defaults' => [
                'privacy' => LegalDocumentDefaults::privacyPolicyHtml(),
                'terms' => LegalDocumentDefaults::termsOfUseHtml(),
            ],
        ];
    }

    /**
     * @return array{privacy_url: string, terms_url: string, cookie_banner_enabled: bool}
     */
    public function publicLinks(): array
    {
        return [
            'privacy_url' => route('legal.privacy'),
            'terms_url' => route('legal.terms'),
            'cookie_banner_enabled' => $this->cookieBannerEnabled(),
        ];
    }
}
