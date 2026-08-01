<?php

namespace App\Support;

use App\Models\ProductCoproducer;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReferralAttributionService;
use App\Services\ReferralCommissionRecorder;
use Illuminate\Http\Request;

/**
 * Controle global de abertura/fechamento do cadastro público de infoprodutores.
 */
final class InfoproducerRegistrationSettings
{
    public const KEY = 'allow_new_infoproducers';

    public const BLOCKED_MESSAGE = 'No momento, não estamos aceitando novos cadastros de infoprodutores.';

    public static function isAllowed(): bool
    {
        return Setting::get(self::KEY, '1', null) === '1';
    }

    /**
     * Cadastro público permitido: aberto globalmente, ou com convite/indicação válida de seller ativo.
     */
    public static function requestMayRegister(Request $request): bool
    {
        if (self::isAllowed()) {
            return true;
        }

        return self::hasValidInviteFromActiveSeller($request);
    }

    public static function hasValidInviteFromActiveSeller(Request $request): bool
    {
        $token = trim((string) ($request->input('coproducer_invite') ?: $request->query('coproducer_invite', '')));
        if ($token !== '' && self::isValidCoproductionInviteFromActiveSeller($token)) {
            return true;
        }

        $code = ReferralAttributionService::resolveCodeFromRequest($request);
        if ($code !== null && ReferralAttributionService::findReferrerByCode($code) !== null) {
            return true;
        }

        return false;
    }

    public static function isValidCoproductionInviteFromActiveSeller(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $invitation = ProductCoproducer::query()
            ->where('token', $token)
            ->where('status', ProductCoproducer::STATUS_PENDING)
            ->with(['inviter', 'product'])
            ->first();

        if ($invitation === null) {
            return false;
        }

        $inviter = $invitation->inviter;
        if (! $inviter instanceof User) {
            $tenantId = (int) ($invitation->product?->tenant_id ?? 0);
            if ($tenantId <= 0) {
                return false;
            }
            $inviter = User::query()->find($tenantId);
        }

        if (! $inviter instanceof User) {
            return false;
        }

        return ReferralCommissionRecorder::isEligibleReferrer($inviter)
            || (
                $inviter->isInfoprodutor()
                && ! in_array((string) ($inviter->account_status ?? 'approved'), ['blocked', 'rejected', 'suspended'], true)
            );
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            self::KEY => self::isAllowed() ? '1' : '0',
        ];
    }
}
