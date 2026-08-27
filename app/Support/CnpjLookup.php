<?php

namespace App\Support;

use App\Models\User;
use App\Services\Cnpj\BrasilApiCnpjClient;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Snapshot da consulta CNPJ (BrasilAPI) para cadastro PJ e análise KYC.
 */
final class CnpjLookup
{
    /** @var list<string> */
    public const IRREGULAR_SITUATIONS = [
        'BAIXADA',
        'INAPTA',
        'SUSPENSA',
        'NULA',
    ];

    public static function columnExists(): bool
    {
        return Schema::hasColumn('users', 'cnpj_lookup');
    }

    /**
     * @return array<string, mixed>
     */
    public static function persistForUser(
        User $user,
        string $cnpjDigits,
        string $submittedCompanyName,
        ?string $clientSuggestedRazao = null,
        bool $fresh = false,
    ): array {
        if (! self::columnExists()) {
            return [];
        }

        $previous = is_array($user->cnpj_lookup) ? $user->cnpj_lookup : [];
        $result = app(BrasilApiCnpjClient::class)->lookup($cnpjDigits, $fresh);

        if (($result['status'] ?? '') === BrasilApiCnpjClient::STATUS_OK && is_array($result['payload'] ?? null)) {
            $snapshot = self::fromOfficialPayload(
                $result['payload'],
                $submittedCompanyName,
                $cnpjDigits,
            );
            $user->forceFill(['cnpj_lookup' => $snapshot])->save();

            return $snapshot;
        }

        if ($fresh && self::hasOfficialData($previous)) {
            $previous['last_error'] = (string) ($result['error'] ?? $result['status'] ?? 'unavailable');
            $previous['last_error_at'] = now()->toIso8601String();
            $previous['last_refresh_status'] = (string) ($result['status'] ?? BrasilApiCnpjClient::STATUS_UNAVAILABLE);
            $user->forceFill(['cnpj_lookup' => $previous])->save();

            return $previous;
        }

        $failed = self::failedSnapshot(
            (string) ($result['status'] ?? BrasilApiCnpjClient::STATUS_UNAVAILABLE),
            isset($result['error']) ? (string) $result['error'] : null,
            $submittedCompanyName,
            $clientSuggestedRazao,
            $cnpjDigits,
        );
        $user->forceFill(['cnpj_lookup' => $failed])->save();

        return $failed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fromOfficialPayload(array $payload, string $submittedCompanyName, string $cnpjDigits): array
    {
        $razao = trim((string) ($payload['razao_social'] ?? ''));
        $situacao = self::normalizeSituacao((string) ($payload['descricao_situacao_cadastral'] ?? ''));
        $address = self::extractAddress($payload);

        return [
            'status' => BrasilApiCnpjClient::STATUS_OK,
            'source' => 'brasilapi',
            'checked_at' => now()->toIso8601String(),
            'cnpj' => BrazilianDocuments::digits($cnpjDigits) ?: BrazilianDocuments::digits((string) ($payload['cnpj'] ?? '')),
            'razao_social' => $razao !== '' ? $razao : null,
            'nome_fantasia' => trim((string) ($payload['nome_fantasia'] ?? '')) ?: null,
            'situacao' => $situacao !== '' ? $situacao : null,
            'situacao_irregular' => self::isIrregular($situacao),
            'data_inicio_atividade' => trim((string) ($payload['data_inicio_atividade'] ?? '')) ?: null,
            'cnae' => trim((string) ($payload['cnae_fiscal_descricao'] ?? '')) ?: null,
            'natureza_juridica' => trim((string) ($payload['natureza_juridica'] ?? '')) ?: null,
            'address' => $address,
            'qsa' => self::extractQsa($payload),
            'submitted_company_name' => trim($submittedCompanyName) !== '' ? trim($submittedCompanyName) : null,
            'razao_social_overridden' => $razao !== '' && ! self::namesMatch($submittedCompanyName, $razao),
            'last_error' => null,
            'last_error_at' => null,
            'last_refresh_status' => BrasilApiCnpjClient::STATUS_OK,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function failedSnapshot(
        string $status,
        ?string $error,
        string $submittedCompanyName,
        ?string $clientSuggestedRazao,
        string $cnpjDigits,
    ): array {
        $suggested = trim((string) $clientSuggestedRazao);
        $submitted = trim($submittedCompanyName);
        $overridden = $suggested !== '' && $submitted !== '' && ! self::namesMatch($submitted, $suggested);

        return [
            'status' => $status !== '' ? $status : BrasilApiCnpjClient::STATUS_UNAVAILABLE,
            'source' => 'brasilapi',
            'checked_at' => now()->toIso8601String(),
            'cnpj' => BrazilianDocuments::digits($cnpjDigits),
            'razao_social' => null,
            'nome_fantasia' => null,
            'situacao' => null,
            'situacao_irregular' => false,
            'data_inicio_atividade' => null,
            'cnae' => null,
            'natureza_juridica' => null,
            'address' => null,
            'qsa' => [],
            'submitted_company_name' => $submitted !== '' ? $submitted : null,
            'razao_social_overridden' => $overridden,
            'suggested_from_client' => $suggested !== '' ? $suggested : null,
            'has_official_data' => false,
            'last_error' => $error ?: $status,
            'last_error_at' => now()->toIso8601String(),
            'last_refresh_status' => $status !== '' ? $status : BrasilApiCnpjClient::STATUS_UNAVAILABLE,
        ];
    }

    /**
     * Payload para o wizard (não persiste).
     *
     * @return array<string, mixed>
     */
    public static function publicWizardPayload(array $lookup): array
    {
        if (($lookup['status'] ?? '') !== BrasilApiCnpjClient::STATUS_OK || ! is_array($lookup['payload'] ?? null)) {
            return [
                'ok' => false,
                'status' => (string) ($lookup['status'] ?? BrasilApiCnpjClient::STATUS_UNAVAILABLE),
                'razao_social' => null,
                'nome_fantasia' => null,
                'situacao' => null,
                'situacao_irregular' => false,
                'situacao_message' => null,
            ];
        }

        $payload = $lookup['payload'];
        $razao = trim((string) ($payload['razao_social'] ?? ''));
        $situacao = self::normalizeSituacao((string) ($payload['descricao_situacao_cadastral'] ?? ''));
        $irregular = self::isIrregular($situacao);

        return [
            'ok' => true,
            'status' => BrasilApiCnpjClient::STATUS_OK,
            'razao_social' => $razao !== '' ? $razao : null,
            'nome_fantasia' => trim((string) ($payload['nome_fantasia'] ?? '')) ?: null,
            'situacao' => $situacao !== '' ? $situacao : null,
            'situacao_irregular' => $irregular,
            'situacao_message' => $irregular
                ? self::sellerSituacaoMessage($situacao)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forKycAdmin(User $user): ?array
    {
        if ($user->person_type !== 'pj' && ! PjConversion::hasCnpj($user)) {
            return null;
        }

        $raw = is_array($user->cnpj_lookup) ? $user->cnpj_lookup : [];
        $hasOfficial = self::hasOfficialData($raw);
        $status = (string) ($raw['status'] ?? '');
        $failed = $status !== BrasilApiCnpjClient::STATUS_OK || ! $hasOfficial;
        $refreshFailed = filled($raw['last_error'] ?? null)
            && ($raw['last_refresh_status'] ?? '') !== BrasilApiCnpjClient::STATUS_OK;
        $irregular = (bool) ($raw['situacao_irregular'] ?? false);
        $overridden = (bool) ($raw['razao_social_overridden'] ?? false);

        $alerts = [];
        if ($failed && ! $hasOfficial) {
            $alerts[] = [
                'tone' => 'warning',
                'code' => 'lookup_failed',
                'message' => 'Consulta CNPJ via API não foi bem-sucedida. Peça atenção na análise: os dados da Receita não puderam ser confirmados automaticamente.',
            ];
        } elseif ($refreshFailed && $hasOfficial) {
            $alerts[] = [
                'tone' => 'warning',
                'code' => 'refresh_failed',
                'message' => 'A nova consulta via API não foi bem-sucedida. Os dados abaixo são da última consulta que funcionou.',
            ];
        }
        if ($irregular) {
            $situacao = (string) ($raw['situacao'] ?? 'IRREGULAR');
            $alerts[] = [
                'tone' => 'danger',
                'code' => 'situacao_irregular',
                'message' => 'Situação cadastral na Receita: '.$situacao.'. Confira com atenção antes de aprovar.',
            ];
        }
        if ($overridden) {
            $alerts[] = [
                'tone' => 'warning',
                'code' => 'razao_editada',
                'message' => 'O infoprodutor recusou ou editou a razão social sugerida pela consulta automática.',
            ];
        }

        return [
            'status' => $status !== '' ? $status : BrasilApiCnpjClient::STATUS_UNAVAILABLE,
            'needs_attention' => $alerts !== [],
            'has_official_data' => $hasOfficial,
            'alerts' => $alerts,
            'checked_at' => $raw['checked_at'] ?? null,
            'last_error_at' => $raw['last_error_at'] ?? null,
            'official' => [
                'cnpj' => isset($raw['cnpj']) ? BrazilianDocuments::formatCnpj((string) $raw['cnpj']) : null,
                'razao_social' => $raw['razao_social'] ?? null,
                'nome_fantasia' => $raw['nome_fantasia'] ?? null,
                'situacao' => $raw['situacao'] ?? null,
                'situacao_irregular' => $irregular,
                'data_inicio_atividade' => $raw['data_inicio_atividade'] ?? null,
                'cnae' => $raw['cnae'] ?? null,
                'natureza_juridica' => $raw['natureza_juridica'] ?? null,
                'address_line' => self::formatAddressLine(is_array($raw['address'] ?? null) ? $raw['address'] : []),
                'qsa' => is_array($raw['qsa'] ?? null) ? $raw['qsa'] : [],
            ],
            'submitted' => [
                'company_name' => $user->person_type === 'pj'
                    ? $user->company_name
                    : (PjConversion::companyName($user) ?? $user->company_name),
                'address_line' => self::formatSubmittedAddress($user),
            ],
            'razao_social_overridden' => $overridden,
        ];
    }

    public static function namesMatch(?string $a, ?string $b): bool
    {
        $na = self::normalizeName($a);
        $nb = self::normalizeName($b);

        return $na !== '' && $na === $nb;
    }

    public static function isIrregular(?string $situacao): bool
    {
        $s = self::normalizeSituacao((string) $situacao);
        if ($s === '') {
            return false;
        }

        return in_array($s, self::IRREGULAR_SITUATIONS, true);
    }

    public static function sellerSituacaoMessage(string $situacao): string
    {
        $s = self::normalizeSituacao($situacao);
        $label = $s !== '' ? $s : 'IRREGULAR';

        return 'A Receita Federal registra este CNPJ como '.$label.'. Você pode seguir o cadastro, mas a análise da plataforma vai revisar esta situação.';
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function hasOfficialData(array $raw): bool
    {
        if (($raw['has_official_data'] ?? null) === false) {
            return false;
        }

        return ($raw['status'] ?? '') === BrasilApiCnpjClient::STATUS_OK
            && filled($raw['razao_social'] ?? null);
    }

    private static function normalizeSituacao(string $value): string
    {
        return mb_strtoupper(trim($value));
    }

    public static function normalizeName(?string $name): string
    {
        $s = trim((string) $name);
        if ($s === '') {
            return '';
        }
        $s = Str::upper(Str::ascii($s));
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, ?string>
     */
    private static function extractAddress(array $payload): array
    {
        $tipo = trim((string) ($payload['descricao_tipo_de_logradouro'] ?? ''));
        $logradouro = trim((string) ($payload['logradouro'] ?? ''));
        $street = trim($tipo.' '.$logradouro);

        return [
            'zip' => BrazilianDocuments::digits((string) ($payload['cep'] ?? '')) ?: null,
            'street' => $street !== '' ? $street : null,
            'number' => trim((string) ($payload['numero'] ?? '')) ?: null,
            'complement' => trim((string) ($payload['complemento'] ?? '')) ?: null,
            'neighborhood' => trim((string) ($payload['bairro'] ?? '')) ?: null,
            'city' => trim((string) ($payload['municipio'] ?? '')) ?: null,
            'state' => strtoupper(trim((string) ($payload['uf'] ?? ''))) ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function extractQsa(array $payload): array
    {
        $qsa = $payload['qsa'] ?? [];
        if (! is_array($qsa)) {
            return [];
        }

        $names = [];
        foreach ($qsa as $row) {
            if (! is_array($row)) {
                continue;
            }
            $nome = trim((string) ($row['nome_socio'] ?? ''));
            if ($nome === '') {
                continue;
            }
            $qual = trim((string) ($row['qualificacao_socio'] ?? ''));
            $names[] = $qual !== '' ? $nome.' ('.$qual.')' : $nome;
            if (count($names) >= 12) {
                break;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private static function formatAddressLine(array $address): ?string
    {
        $street = trim((string) ($address['street'] ?? ''));
        $number = trim((string) ($address['number'] ?? ''));
        $parts = array_filter([
            $street !== '' ? $street.($number !== '' ? ', '.$number : '') : ($number !== '' ? $number : null),
            trim((string) ($address['complement'] ?? '')) ?: null,
            trim((string) ($address['neighborhood'] ?? '')) ?: null,
            trim((string) ($address['city'] ?? '')) ?: null,
            strtoupper(trim((string) ($address['state'] ?? ''))) ?: null,
            self::formatCep((string) ($address['zip'] ?? '')),
        ]);

        return $parts !== [] ? implode(' — ', $parts) : null;
    }

    private static function formatSubmittedAddress(User $user): ?string
    {
        return self::formatAddressLine([
            'street' => $user->address_street,
            'number' => $user->address_number,
            'complement' => $user->address_complement,
            'neighborhood' => $user->address_neighborhood,
            'city' => $user->address_city,
            'state' => $user->address_state,
            'zip' => $user->address_zip,
        ]);
    }

    private static function formatCep(string $zip): ?string
    {
        $d = preg_replace('/\D/', '', $zip) ?? '';
        if (strlen($d) !== 8) {
            return trim($zip) !== '' ? trim($zip) : null;
        }

        return substr($d, 0, 5).'-'.substr($d, 5);
    }
}
