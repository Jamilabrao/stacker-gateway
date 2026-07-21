<?php

namespace App\Support;

use App\Http\Controllers\InfoprodutorRegistrationController;
use App\Models\User;
use App\Services\Payout\PayoutUserSettings;
use App\Services\StorageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Dados de cadastro/perfil do infoprodutor para painel admin e financeiro do vendedor.
 */
final class MerchantProfileSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function forUser(User $user, bool $maskDocuments = false): array
    {
        $personType = Schema::hasColumn('users', 'person_type') ? (string) ($user->person_type ?? 'pf') : 'pf';
        $docRaw = (string) ($user->document ?? '');
        $docDisplay = $maskDocuments
            ? ($personType === 'pj' ? self::maskCnpj($docRaw) : self::maskCpf($docRaw))
            : ($personType === 'pj' ? self::formatCnpj($docRaw) : self::formatCpf($docRaw));

        $birth = null;
        if (Schema::hasColumn('users', 'birth_date') && $user->birth_date !== null) {
            try {
                $birth = Carbon::parse($user->birth_date)->format('d/m/Y');
            } catch (\Throwable) {
                $birth = (string) $user->birth_date;
            }
        }

        $repCpfRaw = Schema::hasColumn('users', 'legal_representative_cpf')
            ? (string) ($user->legal_representative_cpf ?? '')
            : '';
        $repCpfDisplay = $repCpfRaw !== ''
            ? ($maskDocuments ? self::maskCpf($repCpfRaw) : self::formatCpf($repCpfRaw))
            : null;

        $revenueLabel = null;
        if (Schema::hasColumn('users', 'monthly_revenue_range') && $user->monthly_revenue_range) {
            foreach (InfoprodutorRegistrationController::revenueRangeOptions() as $opt) {
                if (($opt['value'] ?? '') === $user->monthly_revenue_range) {
                    $revenueLabel = $opt['label'] ?? null;
                    break;
                }
            }
            $revenueLabel ??= (string) $user->monthly_revenue_range;
        }

        $zip = (string) ($user->address_zip ?? '');
        $zipDisplay = strlen(preg_replace('/\D/', '', $zip) ?? '') === 8
            ? self::formatCep($zip)
            : ($zip !== '' ? $zip : null);

        $settings = is_array($user->payout_settings) ? $user->payout_settings : [];
        $pixKey = PayoutUserSettings::cajuPixKey($settings);
        if ($pixKey === '') {
            $pixKey = PayoutUserSettings::pixKey($settings);
        }
        $pixKeyType = PayoutUserSettings::cajuPixKeyType($settings);
        if ($pixKeyType === '') {
            $pixKeyType = PayoutUserSettings::pixKeyType($settings);
        }
        $pixOwnerDocument = PayoutUserSettings::cajuPixOwnerDocument($settings);
        $pixLabel = PayoutUserSettings::pixLabel($settings);

        $whatsapp = self::resolveWhatsApp($user, $pixKeyType, $pixKey);

        $avatarUrl = null;
        if (! empty($user->avatar)) {
            $avatarUrl = app(StorageService::class)->url($user->avatar);
        }

        return [
            'person_type' => $personType,
            'person_type_label' => $personType === 'pj' ? 'Pessoa jurídica' : 'Pessoa física',
            'name' => (string) ($user->name ?? ''),
            'email' => (string) ($user->email ?? ''),
            'phone' => $whatsapp['display'] ?? null,
            'username' => Schema::hasColumn('users', 'username') ? (trim((string) ($user->username ?? '')) ?: null) : null,
            'avatar_url' => $avatarUrl,
            'birth_date' => $birth,
            'document' => $docDisplay !== '' && $docDisplay !== '—' ? $docDisplay : null,
            'document_raw' => $docRaw !== '' ? $docRaw : null,
            'company_name' => $personType === 'pj' ? (trim((string) ($user->company_name ?? '')) ?: null) : null,
            'legal_representative_cpf' => $repCpfDisplay,
            'address_zip' => $zipDisplay,
            'address_street' => trim((string) ($user->address_street ?? '')) ?: null,
            'address_number' => trim((string) ($user->address_number ?? '')) ?: null,
            'address_complement' => trim((string) ($user->address_complement ?? '')) ?: null,
            'address_neighborhood' => trim((string) ($user->address_neighborhood ?? '')) ?: null,
            'address_city' => trim((string) ($user->address_city ?? '')) ?: null,
            'address_state' => strtoupper(trim((string) ($user->address_state ?? ''))) ?: null,
            'address_line' => self::formatAddressLine($user),
            'monthly_revenue_label' => $revenueLabel,
            'monthly_revenue_range' => Schema::hasColumn('users', 'monthly_revenue_range')
                ? ($user->monthly_revenue_range ?? null)
                : null,
            'kyc_status' => Schema::hasColumn('users', 'kyc_status') ? ($user->kyc_status ?? User::KYC_NOT_SUBMITTED) : null,
            'kyc_rejection_reason' => Schema::hasColumn('users', 'kyc_rejection_reason')
                ? (trim((string) ($user->kyc_rejection_reason ?? '')) ?: null)
                : null,
            'kyc_reviewed_at' => Schema::hasColumn('users', 'kyc_reviewed_at')
                ? $user->kyc_reviewed_at?->toIso8601String()
                : null,
            'kyc_needs_document_review' => Schema::hasColumn('users', 'kyc_needs_document_review')
                ? (bool) ($user->kyc_needs_document_review ?? false)
                : false,
            'account_status' => $user->account_status ?? 'approved',
            'seller_onboarded_at' => Schema::hasColumn('users', 'seller_onboarded_at')
                ? $user->seller_onboarded_at?->toIso8601String()
                : null,
            'created_at' => $user->created_at?->toIso8601String(),
            'privacy_policy_accepted_at' => Schema::hasColumn('users', 'privacy_policy_accepted_at')
                ? $user->privacy_policy_accepted_at?->toIso8601String()
                : null,
            'terms_accepted_at' => Schema::hasColumn('users', 'terms_accepted_at')
                ? $user->terms_accepted_at?->toIso8601String()
                : null,
            'payout_pix_key' => $pixKey !== '' ? $pixKey : null,
            'payout_pix_key_type' => $pixKeyType !== '' ? $pixKeyType : null,
            'payout_pix_key_type_label' => self::pixKeyTypeLabel($pixKeyType),
            'payout_pix_label' => $pixLabel !== '' ? $pixLabel : null,
            'payout_pix_owner_document' => $pixOwnerDocument !== ''
                ? self::formatCpfOrCnpj($pixOwnerDocument)
                : null,
            'whatsapp' => $whatsapp['display'] ?? null,
            'whatsapp_url' => $whatsapp['url'] ?? null,
        ];
    }

    /**
     * Contato WhatsApp: telefone cadastrado no usuário; fallback só se a chave PIX for telefone.
     *
     * @return array{display?: string, url?: string}
     */
    private static function resolveWhatsApp(User $user, string $pixKeyType, string $pixKey): array
    {
        if (Schema::hasColumn('users', 'phone')) {
            $fromUser = self::whatsappFromPhoneDigits((string) ($user->phone ?? ''));
            if ($fromUser !== []) {
                return $fromUser;
            }
        }

        return self::whatsappFromPixKey($pixKeyType, $pixKey);
    }

    /**
     * @return array{display?: string, url?: string}
     */
    private static function whatsappFromPhoneDigits(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 10) {
            return [];
        }

        if (strlen($digits) <= 11 && ! str_starts_with($digits, '55')) {
            $digits = '55'.$digits;
        }

        return [
            'display' => self::formatPhoneBr($digits),
            'url' => 'https://wa.me/'.$digits,
        ];
    }

    /**
     * @return array{display?: string, url?: string}
     */
    private static function whatsappFromPixKey(string $pixKeyType, string $pixKey): array
    {
        if ($pixKeyType !== 'phone' || trim($pixKey) === '') {
            return [];
        }

        return self::whatsappFromPhoneDigits($pixKey);
    }

    private static function formatAddressLine(User $user): ?string
    {
        $street = trim((string) ($user->address_street ?? ''));
        $number = trim((string) ($user->address_number ?? ''));
        if ($street === '' && $number === '') {
            return null;
        }

        $parts = array_filter([
            $street !== '' ? $street.($number !== '' ? ', '.$number : '') : $number,
            trim((string) ($user->address_complement ?? '')) ?: null,
            trim((string) ($user->address_neighborhood ?? '')) ?: null,
            trim((string) ($user->address_city ?? '')) ?: null,
            strtoupper(trim((string) ($user->address_state ?? ''))) ?: null,
            self::formatCep((string) ($user->address_zip ?? '')) ?: null,
        ]);

        return $parts !== [] ? implode(' — ', $parts) : null;
    }

    private static function pixKeyTypeLabel(string $type): ?string
    {
        $map = [
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'E-mail',
            'phone' => 'Telefone / WhatsApp',
            'evp' => 'Chave aleatória',
        ];

        $type = strtolower(trim($type));

        return $type !== '' ? ($map[$type] ?? strtoupper($type)) : null;
    }

    private static function formatCpf(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';
        if (strlen($d) !== 11) {
            return $digits !== '' ? $digits : '—';
        }

        return sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9, 2));
    }

    private static function maskCpf(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';
        if (strlen($d) !== 11) {
            return $digits !== '' ? $digits : '—';
        }

        return '***.***.***-'.substr($d, 9, 2);
    }

    private static function formatCnpj(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';
        if (strlen($d) !== 14) {
            return $digits !== '' ? $digits : '—';
        }

        return sprintf('%s.%s.%s/%s-%s', substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12, 2));
    }

    private static function maskCnpj(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';
        if (strlen($d) !== 14) {
            return $digits !== '' ? $digits : '—';
        }

        return '**.***.***/****-'.substr($d, 12, 2);
    }

    private static function formatCpfOrCnpj(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';

        return strlen($d) === 14 ? self::formatCnpj($d) : self::formatCpf($d);
    }

    private static function formatCep(string $zip): ?string
    {
        $d = preg_replace('/\D/', '', $zip) ?? '';
        if (strlen($d) !== 8) {
            return trim($zip) !== '' ? trim($zip) : null;
        }

        return substr($d, 0, 5).'-'.substr($d, 5);
    }

    private static function formatPhoneBr(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';
        if (str_starts_with($d, '55') && strlen($d) >= 12) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
        }

        return $digits;
    }
}
