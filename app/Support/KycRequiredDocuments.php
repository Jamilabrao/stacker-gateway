<?php

namespace App\Support;

use App\Models\KycDocument;
use App\Models\User;

class KycRequiredDocuments
{
    /**
     * @return list<string>
     */
    public static function kindsForUser(User $user): array
    {
        $kinds = [KycDocument::KIND_RG_FRONT, KycDocument::KIND_RG_BACK];
        if ($user->person_type === 'pj') {
            $kinds[] = KycDocument::KIND_COMPANY_DOCUMENT;
        }

        return $kinds;
    }

    /**
     * @return list<string> kinds faltantes
     */
    public static function missingKindsForUser(User $user): array
    {
        $required = self::kindsForUser($user);
        $existing = KycDocument::query()
            ->where('user_id', $user->id)
            ->whereIn('kind', $required)
            ->pluck('kind')
            ->all();

        return array_values(array_diff($required, $existing));
    }

    public static function hasAllRequired(User $user): bool
    {
        return self::missingKindsForUser($user) === [];
    }

    /**
     * @return list<string> labels legíveis dos documentos faltantes
     */
    public static function missingLabelsForUser(User $user): array
    {
        $labels = [
            KycDocument::KIND_RG_FRONT => 'RG (frente)',
            KycDocument::KIND_RG_BACK => 'RG (verso)',
            KycDocument::KIND_COMPANY_DOCUMENT => 'documento da empresa',
        ];

        return array_map(
            fn (string $k) => $labels[$k] ?? $k,
            self::missingKindsForUser($user)
        );
    }
}
