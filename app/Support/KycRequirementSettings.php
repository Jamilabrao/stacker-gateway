<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Configuração global (plataforma): quais documentos o KYC exige no fluxo v2.
 * Cada instalação pode ser mais ou menos criteriosa.
 */
final class KycRequirementSettings
{
    public const KEY = 'kyc_document_requirements';

    /**
     * @return array{
     *     allowed_identity_types: list<string>,
     *     require_address_proof: bool,
     *     require_selfie_with_document: bool,
     *     require_company_address_proof: bool,
     *     require_company_constitution: bool
     * }
     */
    public static function defaults(): array
    {
        return [
            'allowed_identity_types' => [
                KycRequiredDocuments::IDENTITY_RG,
                KycRequiredDocuments::IDENTITY_CNH,
                KycRequiredDocuments::IDENTITY_PASSPORT,
            ],
            'require_address_proof' => true,
            'require_selfie_with_document' => true,
            'require_company_address_proof' => true,
            'require_company_constitution' => true,
        ];
    }

    /**
     * @return array{
     *     allowed_identity_types: list<string>,
     *     require_address_proof: bool,
     *     require_selfie_with_document: bool,
     *     require_company_address_proof: bool,
     *     require_company_constitution: bool
     * }
     */
    public static function resolved(): array
    {
        $raw = Setting::get(self::KEY, null, null);
        $decoded = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true) ?: [];
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        return self::normalize($decoded);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     allowed_identity_types: list<string>,
     *     require_address_proof: bool,
     *     require_selfie_with_document: bool,
     *     require_company_address_proof: bool,
     *     require_company_constitution: bool
     * }
     */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();

        $types = $input['allowed_identity_types'] ?? $defaults['allowed_identity_types'];
        if (! is_array($types)) {
            $types = $defaults['allowed_identity_types'];
        }
        $types = array_values(array_unique(array_filter(
            array_map(
                fn ($t) => KycRequiredDocuments::normalizeIdentityType($t),
                $types
            )
        )));
        if ($types === []) {
            $types = [KycRequiredDocuments::IDENTITY_RG];
        }

        return [
            'allowed_identity_types' => $types,
            'require_address_proof' => self::toBool($input['require_address_proof'] ?? $defaults['require_address_proof']),
            'require_selfie_with_document' => self::toBool($input['require_selfie_with_document'] ?? $defaults['require_selfie_with_document']),
            'require_company_address_proof' => self::toBool($input['require_company_address_proof'] ?? $defaults['require_company_address_proof']),
            'require_company_constitution' => self::toBool($input['require_company_constitution'] ?? $defaults['require_company_constitution']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        $cfg = self::resolved();

        return [
            'kyc_allowed_identity_types' => $cfg['allowed_identity_types'],
            'kyc_require_address_proof' => $cfg['require_address_proof'] ? '1' : '0',
            'kyc_require_selfie_with_document' => $cfg['require_selfie_with_document'] ? '1' : '0',
            'kyc_require_company_address_proof' => $cfg['require_company_address_proof'] ? '1' : '0',
            'kyc_require_company_constitution' => $cfg['require_company_constitution'] ? '1' : '0',
        ];
    }

    /**
     * Payload compacto para o formulário do seller.
     *
     * @return array{
     *     allowed_identity_types: list<string>,
     *     require_address_proof: bool,
     *     require_selfie_with_document: bool,
     *     require_company_address_proof: bool,
     *     require_company_constitution: bool
     * }
     */
    public static function forSellerForm(): array
    {
        return self::resolved();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persistFromValidated(array $validated): void
    {
        if (! array_key_exists('kyc_allowed_identity_types', $validated)
            && ! array_key_exists('kyc_require_address_proof', $validated)
            && ! array_key_exists('kyc_require_selfie_with_document', $validated)
            && ! array_key_exists('kyc_require_company_address_proof', $validated)
            && ! array_key_exists('kyc_require_company_constitution', $validated)) {
            return;
        }

        $current = self::resolved();
        $payload = [
            'allowed_identity_types' => $validated['kyc_allowed_identity_types'] ?? $current['allowed_identity_types'],
            'require_address_proof' => array_key_exists('kyc_require_address_proof', $validated)
                ? self::toBool($validated['kyc_require_address_proof'])
                : $current['require_address_proof'],
            'require_selfie_with_document' => array_key_exists('kyc_require_selfie_with_document', $validated)
                ? self::toBool($validated['kyc_require_selfie_with_document'])
                : $current['require_selfie_with_document'],
            'require_company_address_proof' => array_key_exists('kyc_require_company_address_proof', $validated)
                ? self::toBool($validated['kyc_require_company_address_proof'])
                : $current['require_company_address_proof'],
            'require_company_constitution' => array_key_exists('kyc_require_company_constitution', $validated)
                ? self::toBool($validated['kyc_require_company_constitution'])
                : $current['require_company_constitution'],
        ];

        Setting::set(self::KEY, json_encode(self::normalize($payload), JSON_UNESCAPED_UNICODE), null);
    }

    public static function isIdentityTypeAllowed(string $type): bool
    {
        $normalized = KycRequiredDocuments::normalizeIdentityType($type);
        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, self::resolved()['allowed_identity_types'], true);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'on'
            || $value === 'yes';
    }
}
