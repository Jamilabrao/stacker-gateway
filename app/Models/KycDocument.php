<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KycDocument extends Model
{
    /** Faces do documento de identificação (RG/CIN, CNH, passaporte). */
    public const KIND_RG_FRONT = 'rg_front';

    public const KIND_RG_BACK = 'rg_back';

    /** @deprecated Legado — não usado no fluxo v2. */
    public const KIND_CNPJ_CARD = 'cnpj_card';

    /** Contrato social / ato constitutivo (PJ não-MEI). */
    public const KIND_SOCIAL_CONTRACT = 'social_contract';

    /** @deprecated Legado v1 — cartão CNPJ ou contrato genérico. */
    public const KIND_COMPANY_DOCUMENT = 'company_document';

    public const KIND_ADDRESS_PROOF = 'address_proof';

    public const KIND_SELFIE_WITH_DOCUMENT = 'selfie_with_document';

    public const KIND_COMPANY_ADDRESS_PROOF = 'company_address_proof';

    /** Certificado CCMEI (MEI). */
    public const KIND_CCMEI = 'ccmei';

    protected $fillable = [
        'user_id',
        'public_token',
        'kind',
        'disk_path',
        'original_mime',
        'size_bytes',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (KycDocument $doc) {
            if (empty($doc->public_token)) {
                $doc->public_token = (string) Str::uuid();
            }
        });
    }

    /** URL pública do painel usa token UUID — não expõe id sequencial. */
    public function getRouteKeyName(): string
    {
        return 'public_token';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('kyc_documents', 'superseded_at')) {
            return $query;
        }

        return $query->whereNull('superseded_at');
    }

    public function isActive(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('kyc_documents', 'superseded_at')) {
            return true;
        }

        return $this->superseded_at === null;
    }
}
