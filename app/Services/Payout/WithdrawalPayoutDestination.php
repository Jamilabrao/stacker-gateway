<?php

namespace App\Services\Payout;

use App\Models\Withdrawal;

/**
 * Destino PIX vinculado ao saque (não à chave master do infoprodutor).
 */
class WithdrawalPayoutDestination
{
    /**
     * @param  array{pix_key: string, pix_key_type: string, key_owner_document?: string}  $destination
     */
    public static function attach(Withdrawal $withdrawal, array $destination, string $source = 'api_request'): void
    {
        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta['destination_snapshot'] = array_filter([
            'pix_key' => trim((string) ($destination['pix_key'] ?? '')),
            'pix_key_type' => trim((string) ($destination['pix_key_type'] ?? '')),
            'receiver_document' => trim((string) ($destination['key_owner_document'] ?? '')),
            'source' => $source,
            'captured_at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== '');

        $withdrawal->forceFill(['payout_meta' => $meta])->save();
    }

    /**
     * @return array{pix_key: string, pix_key_type: string, key_owner_document: string}|null
     */
    public static function fromWithdrawal(Withdrawal $withdrawal): ?array
    {
        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $snap = is_array($meta['destination_snapshot'] ?? null) ? $meta['destination_snapshot'] : null;
        if ($snap === null) {
            return null;
        }

        $pixKey = trim((string) ($snap['pix_key'] ?? ''));
        $pixKeyType = trim((string) ($snap['pix_key_type'] ?? ''));
        $ownerDoc = trim((string) ($snap['receiver_document'] ?? $snap['key_owner_document'] ?? ''));

        if ($pixKey === '' || $pixKeyType === '') {
            return null;
        }

        return [
            'pix_key' => $pixKey,
            'pix_key_type' => $pixKeyType,
            'key_owner_document' => $ownerDoc,
        ];
    }
}
