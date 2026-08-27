<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Migração PF → PJ sem alterar person_type/document até a aprovação do KYC.
 */
final class PjConversion
{
    public const STATUS_COLLECTING = 'collecting_docs';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const ACTIVE_STATUSES = [
        self::STATUS_COLLECTING,
        self::STATUS_PENDING_REVIEW,
    ];

    public static function columnExists(): bool
    {
        return Schema::hasColumn('users', 'pj_conversion');
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(User $user): array
    {
        if (! self::columnExists()) {
            return [];
        }

        return is_array($user->pj_conversion) ? $user->pj_conversion : [];
    }

    public static function status(User $user): ?string
    {
        $status = (string) (self::payload($user)['status'] ?? '');

        return $status !== '' ? $status : null;
    }

    public static function isCollecting(User $user): bool
    {
        return self::status($user) === self::STATUS_COLLECTING;
    }

    public static function isPendingReview(User $user): bool
    {
        return self::status($user) === self::STATUS_PENDING_REVIEW;
    }

    public static function isRejected(User $user): bool
    {
        return self::status($user) === self::STATUS_REJECTED;
    }

    public static function isCollectingOrPending(User $user): bool
    {
        return in_array(self::status($user), self::ACTIVE_STATUSES, true);
    }

    public static function allowsDocumentUpload(User $user): bool
    {
        return self::isCollecting($user);
    }

    public static function cnpj(User $user): ?string
    {
        $cnpj = BrazilianDocuments::digits((string) (self::payload($user)['cnpj'] ?? ''));

        return strlen($cnpj) === 14 ? $cnpj : null;
    }

    public static function hasCnpj(User $user): bool
    {
        return self::cnpj($user) !== null;
    }

    public static function companyName(User $user): ?string
    {
        $name = trim((string) (self::payload($user)['company_name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    public static function companyLegalNature(User $user): ?string
    {
        return KycRequiredDocuments::normalizeCompanyNature(self::payload($user)['company_legal_nature'] ?? $user->company_legal_nature);
    }

    public static function isEligible(User $user): bool
    {
        if (! $user->canAccessSellerPanel()) {
            return false;
        }

        $subject = $user->kycSubjectUser();
        if (($subject->person_type ?? '') !== 'pf') {
            return false;
        }
        if (! $subject->isMerchantOperationallyApproved()) {
            return false;
        }
        if (self::isPendingReview($subject)) {
            return false;
        }

        return self::status($subject) === null || self::isRejected($subject) || self::isCollecting($subject);
    }

    public static function canStart(User $user): bool
    {
        if (! $user->canAccessSellerPanel()) {
            return false;
        }

        $subject = $user->kycSubjectUser();
        if (($subject->person_type ?? '') !== 'pf') {
            return false;
        }
        if (! $subject->isMerchantOperationallyApproved()) {
            return false;
        }

        return ! self::isPendingReview($subject);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forFrontend(User $user): ?array
    {
        $payload = self::payload($user);
        $status = self::status($user);
        if ($status === null) {
            return null;
        }

        $cnpj = self::cnpj($user);

        return [
            'status' => $status,
            'cnpj' => $cnpj,
            'cnpj_formatted' => $cnpj ? BrazilianDocuments::formatCnpj($cnpj) : null,
            'company_name' => self::companyName($user),
            'company_legal_nature' => self::companyLegalNature($user),
            'rejection_reason' => trim((string) ($payload['rejection_reason'] ?? '')) ?: null,
            'started_at' => $payload['started_at'] ?? null,
            'submitted_at' => $payload['submitted_at'] ?? null,
        ];
    }

    public static function cnpjTakenByAnotherUser(string $cnpjDigits, int $ignoreUserId): bool
    {
        $cnpj = BrazilianDocuments::digits($cnpjDigits);
        if (strlen($cnpj) !== 14) {
            return false;
        }

        if (User::query()->where('id', '!=', $ignoreUserId)->where('document', $cnpj)->exists()) {
            return true;
        }

        if (! self::columnExists()) {
            return false;
        }

        return User::query()
            ->where('id', '!=', $ignoreUserId)
            ->where('pj_conversion->cnpj', $cnpj)
            ->whereIn('pj_conversion->status', self::ACTIVE_STATUSES)
            ->exists();
    }
}
