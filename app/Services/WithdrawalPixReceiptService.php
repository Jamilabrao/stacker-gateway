<?php

namespace App\Services;

use App\Models\BrandingSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Services\Payout\PayoutUserSettings;
use App\Support\BrazilianDocumentDigits;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WithdrawalPixReceiptService
{
    public function isAvailable(Withdrawal $withdrawal): bool
    {
        $status = strtolower(trim((string) $withdrawal->status));
        if ($status !== MerchantWithdrawalService::STATUS_PAID) {
            return false;
        }

        // Prefer net_amount; fall back to gross when net was not persisted (legacy rows).
        $net = (float) ($withdrawal->net_amount ?? 0);
        $gross = (float) ($withdrawal->amount ?? 0);

        return $net > 0 || $gross > 0;
    }

    /**
     * @param  array<string, mixed>|null  $pixKeyData
     */
    public function snapshotDestination(Withdrawal $withdrawal, ?User $owner = null, ?array $pixKeyData = null): void
    {
        $owner ??= $this->resolveTenantOwner((int) $withdrawal->tenant_id);
        if ($owner === null) {
            return;
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $pixKey = trim((string) ($pixKeyData['pix_key'] ?? ''));
        $pixKeyType = trim((string) ($pixKeyData['pix_key_type'] ?? ''));
        $ownerDocument = trim((string) ($pixKeyData['key_owner_document'] ?? ''));

        if ($pixKey === '') {
            $pixKey = \App\Services\Payout\PayoutUserSettings::cajuPixKey($settings);
        }
        if ($pixKeyType === '') {
            $pixKeyType = \App\Services\Payout\PayoutUserSettings::cajuPixKeyType($settings);
        }
        if ($ownerDocument === '') {
            $ownerDocument = \App\Services\Payout\PayoutUserSettings::cajuPixOwnerDocument($settings);
        }

        $receiverName = $this->receiverDisplayName($owner);

        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $meta['destination_snapshot'] = array_filter([
            'receiver_name' => $receiverName,
            'receiver_document' => $ownerDocument,
            'pix_key' => $pixKey,
            'pix_key_type' => $pixKeyType,
            'captured_at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== '');

        $withdrawal->update(['payout_meta' => $meta]);
    }

    public function enrichFromCajuPay(Withdrawal $withdrawal): void
    {
        if ($withdrawal->payout_provider !== 'cajupay') {
            return;
        }

        $externalId = trim((string) ($withdrawal->payout_external_id ?? ''));
        if ($externalId === '') {
            return;
        }

        try {
            $record = app(CajuPayPayoutService::class)->fetchPayoutRecord($externalId, (int) $withdrawal->tenant_id);
            if ($record !== null) {
                $this->persistReceiptFromPayload($withdrawal, $record);
            }
        } catch (\Throwable $e) {
            Log::warning('WithdrawalPixReceiptService: enrichFromCajuPay failed', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function persistReceiptFromPayload(Withdrawal $withdrawal, array $payload): void
    {
        $object = $payload;
        if (isset($payload['object']) && is_array($payload['object'])) {
            $object = $payload['object'];
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $nested = $payload['data'];
            $object = isset($nested['object']) && is_array($nested['object']) ? $nested['object'] : $nested;
        }

        $receipt = $this->mapPayloadToReceipt($object);
        if ($receipt === []) {
            return;
        }

        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $existing = is_array($meta['cajupay_receipt'] ?? null) ? $meta['cajupay_receipt'] : [];
        $meta['cajupay_receipt'] = array_merge($existing, $receipt);

        try {
            $withdrawal->update(['payout_meta' => $meta]);
        } catch (\Throwable $e) {
            Log::warning('WithdrawalPixReceiptService: persistReceiptFromPayload failed', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(Withdrawal $withdrawal, bool $includePayerSection = true): array
    {
        if (! $this->isAvailable($withdrawal)) {
            abort(404, 'Comprovante disponível apenas para saques pagos.');
        }

        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $snapshot = is_array($meta['destination_snapshot'] ?? null) ? $meta['destination_snapshot'] : [];
        $receipt = is_array($meta['cajupay_receipt'] ?? null) ? $meta['cajupay_receipt'] : [];

        $owner = $this->resolveTenantOwner((int) $withdrawal->tenant_id);
        $ownerSettings = $this->ownerPayoutSettings($owner);

        $account = null;
        try {
            $account = app(CajuPayAccountResolver::class)->resolveForTenant((int) $withdrawal->tenant_id);
            if ($account === null) {
                $account = app(CajuPayAccountResolver::class)->defaultOrFirstConnected();
            }
        } catch (\Throwable $e) {
            Log::warning('WithdrawalPixReceiptService: account resolve failed', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);
        }

        $paidAt = $this->resolvePaidAt($withdrawal, $receipt, $meta);
        $net = (float) ($withdrawal->net_amount ?? 0);
        $amount = $net > 0 ? $net : (float) ($withdrawal->amount ?? 0);

        $payerName = '—';
        $payerDocument = '—';
        $payerInstitution = '—';
        if ($includePayerSection) {
            $payerName = $this->firstNonEmpty(
                $receipt['payer_name'] ?? null,
                $receipt['provider'] ?? null,
                $account?->name,
                config('app.name', 'Getfy')
            );
            $payerDocument = $this->formatDocument($receipt['payer_document'] ?? null);
            $payerInstitution = $this->firstNonEmpty(
                $receipt['payer_institution'] ?? null,
                $receipt['provider'] ?? null,
                $account?->name
            );
        }

        $receiverName = $this->firstNonEmpty(
            $receipt['receiver_name'] ?? null,
            $snapshot['receiver_name'] ?? null,
            $owner?->name
        );
        $receiverDocument = $this->formatDocument(
            $receipt['receiver_document']
                ?? $snapshot['receiver_document']
                ?? PayoutUserSettings::cajuPixOwnerDocument($ownerSettings)
                ?: $owner?->document
        );
        $receiverInstitution = $this->firstNonEmpty($receipt['receiver_institution'] ?? null);
        $pixKey = $this->firstNonEmpty(
            $snapshot['pix_key'] ?? null,
            PayoutUserSettings::cajuPixKey($ownerSettings),
            PayoutUserSettings::pixKey($ownerSettings)
        );

        $authCode = $this->firstNonEmpty(
            $receipt['psp_reference'] ?? null,
            $receipt['end_to_end_id'] ?? null
        );
        $identification = $includePayerSection
            ? $this->firstNonEmpty($withdrawal->payout_external_id, (string) $withdrawal->id)
            : (string) $withdrawal->id;

        $brandingData = [];
        try {
            if (Schema::hasTable('branding_settings')) {
                $branding = BrandingSetting::query()->whereNull('tenant_id')->first();
                $brandingData = is_array($branding?->data) ? $branding->data : [];
            }
        } catch (\Throwable $e) {
            Log::warning('WithdrawalPixReceiptService: branding load failed', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'app_name' => (string) ($brandingData['app_name'] ?? config('app.name', 'Getfy')),
            'app_logo' => (string) ($brandingData['app_logo_dark'] ?? $brandingData['app_logo'] ?? ''),
            'theme_primary' => (string) ($brandingData['theme_primary'] ?? '#22c55e'),
            'amount_formatted' => 'R$ '.number_format($amount, 2, ',', '.'),
            'paid_at_formatted' => $paidAt?->format('d/m/Y H:i:s') ?? '—',
            'payer_name' => $payerName,
            'payer_document' => $payerDocument,
            'payer_institution' => $payerInstitution,
            'receiver_name' => $receiverName,
            'receiver_document' => $receiverDocument,
            'receiver_institution' => $receiverInstitution,
            'pix_key' => $pixKey,
            'authentication' => $authCode,
            'identification' => $identification,
            'withdrawal_id' => $withdrawal->id,
            'show_payer_section' => $includePayerSection,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapWithdrawalListItem(Withdrawal $w): array
    {
        return [
            'id' => $w->id,
            'tenant_id' => $w->tenant_id,
            'infoprodutor_name' => $w->tenantOwner?->name ?? '—',
            'infoprodutor_email' => $w->tenantOwner?->email,
            'amount' => (float) $w->amount,
            'fee_amount' => (float) ($w->fee_amount ?? 0),
            'net_amount' => (float) ($w->net_amount ?? 0),
            'bucket' => $w->bucket ?? 'pix',
            'status' => (string) $w->status,
            'notes' => $w->notes,
            'created_at' => $w->created_at?->toIso8601String(),
            'payout_manual' => (bool) $w->payout_manual,
            'payout_provider' => $w->payout_provider,
            'payout_external_id' => $w->payout_external_id,
            'payout_last_error' => is_array($w->payout_meta) ? ($w->payout_meta['last_error'] ?? null) : null,
            'payout_last_attempt_at' => is_array($w->payout_meta) ? ($w->payout_meta['last_attempt_at'] ?? null) : null,
            'webhook_last_event' => is_array($w->payout_meta) ? ($w->payout_meta['webhook_last_event'] ?? null) : null,
            'webhook_last_status' => is_array($w->payout_meta) ? ($w->payout_meta['webhook_last_status'] ?? null) : null,
            'webhook_last_at' => is_array($w->payout_meta) ? ($w->payout_meta['webhook_last_at'] ?? null) : null,
            'reconcile_last_api_status' => is_array($w->payout_meta) ? ($w->payout_meta['reconcile_last_api_status'] ?? null) : null,
            'reconcile_last_at' => is_array($w->payout_meta) ? ($w->payout_meta['reconcile_last_at'] ?? null) : null,
            'reconcile_exhausted' => is_array($w->payout_meta) ? (bool) ($w->payout_meta['reconcile_exhausted'] ?? false) : false,
            'api_application_id' => $w->api_application_id,
            'api_application_name' => $w->apiApplication?->name,
            'can_download_receipt' => $this->isAvailable($w),
        ];
    }

    private function resolveTenantOwner(int $tenantId): ?User
    {
        $owner = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();

        if ($owner === null) {
            $owner = User::query()
                ->where('id', $tenantId)
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->first();
        }

        return $owner;
    }

    private function receiverDisplayName(User $owner): string
    {
        $personType = Schema::hasColumn('users', 'person_type') ? (string) ($owner->person_type ?? 'pf') : 'pf';
        if ($personType === 'pj') {
            $company = trim((string) ($owner->company_name ?? ''));

            return $company !== '' ? $company : (string) $owner->name;
        }

        return (string) $owner->name;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function mapPayloadToReceipt(array $object): array
    {
        $paidAt = $this->firstNonEmpty(
            $object['paid_at'] ?? null,
            $object['settled_at'] ?? null,
            $object['completed_at'] ?? null,
            $object['updated_at'] ?? null
        );

        $pspRef = $this->firstNonEmpty(
            $object['psp_reference'] ?? null,
            $object['end_to_end_id'] ?? null,
            $object['e2e_id'] ?? null,
            $object['pix_end_to_end_id'] ?? null
        );

        $payer = is_array($object['payer'] ?? null) ? $object['payer'] : [];
        $receiver = is_array($object['receiver'] ?? null) ? $object['receiver'] : [];

        return array_filter([
            'paid_at' => $paidAt,
            'psp_reference' => $pspRef,
            'end_to_end_id' => $pspRef,
            'payer_name' => $this->firstNonEmpty(
                $object['payer_name'] ?? null,
                $payer['name'] ?? null,
                $object['payer_legal_name'] ?? null
            ),
            'payer_document' => $this->firstNonEmpty(
                $object['payer_document'] ?? null,
                $payer['document'] ?? null,
                $object['payer_tax_id'] ?? null
            ),
            'payer_institution' => $this->firstNonEmpty(
                $object['payer_institution'] ?? null,
                $object['payer_bank_name'] ?? null,
                $payer['bank_name'] ?? null,
                $payer['institution'] ?? null
            ),
            'receiver_name' => $this->firstNonEmpty(
                $object['receiver_name'] ?? null,
                $receiver['name'] ?? null,
                $object['beneficiary_name'] ?? null
            ),
            'receiver_document' => $this->firstNonEmpty(
                $object['receiver_document'] ?? null,
                $receiver['document'] ?? null,
                $object['key_owner_document'] ?? null
            ),
            'receiver_institution' => $this->firstNonEmpty(
                $object['receiver_institution'] ?? null,
                $object['receiver_bank_name'] ?? null,
                $receiver['bank_name'] ?? null,
                $receiver['institution'] ?? null
            ),
            'provider' => $this->firstNonEmpty($object['provider'] ?? null, $object['gateway'] ?? null),
            'raw' => $this->sanitizeRawSubset($object),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function sanitizeRawSubset(array $object): array
    {
        $keys = [
            'id', 'payout_id', 'status', 'provider', 'psp_reference', 'end_to_end_id',
            'payer_name', 'payer_document', 'receiver_name', 'receiver_document',
            'receiver_bank_name', 'paid_at', 'amount_cents',
        ];
        $out = [];
        foreach ($keys as $key) {
            if (isset($object[$key]) && is_scalar($object[$key])) {
                $out[$key] = $object[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $meta
     */
    private function resolvePaidAt(Withdrawal $withdrawal, array $receipt, array $meta): ?Carbon
    {
        foreach ([
            $receipt['paid_at'] ?? null,
            $meta['webhook_last_at'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                try {
                    return Carbon::parse($candidate);
                } catch (\Throwable) {
                }
            }
        }

        try {
            if (Schema::hasTable('wallet_transactions') && Schema::hasColumn('wallet_transactions', 'withdrawal_id')) {
                $wt = WalletTransaction::query()
                    ->where('withdrawal_id', $withdrawal->id)
                    ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
                    ->orderByDesc('id')
                    ->first();
                if ($wt?->created_at) {
                    return $wt->created_at;
                }
            }
        } catch (\Throwable) {
        }

        return $withdrawal->updated_at;
    }

    private function formatDocument(mixed $raw): string
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return '—';
        }
        $digits = BrazilianDocumentDigits::onlyDigits((string) $raw);
        if ($digits === null || $digits === '') {
            return '—';
        }
        if (strlen($digits) === 14) {
            return sprintf(
                '%s.%s.%s/%s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 4),
                substr($digits, 12, 2)
            );
        }
        if (strlen($digits) === 11) {
            return sprintf(
                '%s.%s.%s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 3),
                substr($digits, 9, 2)
            );
        }

        return $digits;
    }

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '—';
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerPayoutSettings(?User $owner): array
    {
        if ($owner === null) {
            return [];
        }

        return is_array($owner->payout_settings) ? $owner->payout_settings : [];
    }
}
