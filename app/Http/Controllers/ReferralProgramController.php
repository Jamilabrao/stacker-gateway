<?php

namespace App\Http\Controllers;

use App\Models\ReferralCommission;
use App\Models\ReferralWallet;
use App\Models\ReferralWithdrawal;
use App\Models\User;
use App\Services\ReferralCommissionRecorder;
use App\Services\ReferralWithdrawalService;
use App\Support\PublicAppUrl;
use App\Support\ReferralProgramSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ReferralProgramController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (! ReferralProgramSettings::isEnabled()) {
            return redirect('/dashboard')->with('error', 'O programa Indique e Ganhe está desativado.');
        }

        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $owner = $user->isTeam() && $user->tenant_id
            ? (User::query()->find($user->tenant_id) ?? $user)
            : $user;

        $code = ReferralCommissionRecorder::ensureReferralCode($owner);
        $link = rtrim(PublicAppUrl::origin(), '/').'/cadastro?ref='.urlencode($code);
        $wallet = ReferralWallet::forUser((int) $owner->id);

        $referred = User::query()
            ->where('referred_by_user_id', $owner->id)
            ->orderByDesc('referred_at')
            ->get(['id', 'name', 'email', 'account_status', 'referred_at', 'created_at'])
            ->map(function (User $u) {
                $earned = Schema::hasTable('referral_commissions')
                    ? (float) ReferralCommission::query()
                        ->where('referred_user_id', $u->id)
                        ->where('status', ReferralCommission::STATUS_AVAILABLE)
                        ->sum('amount')
                    : 0;

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'account_status' => $u->account_status,
                    'referred_at' => $u->referred_at?->toIso8601String(),
                    'eligible' => ReferralCommissionRecorder::isWithinEligibilityWindow($u),
                    'earned' => round($earned, 2),
                ];
            });

        $commissions = ReferralCommission::query()
            ->where('referrer_user_id', $owner->id)
            ->with(['referred:id,name,email', 'order:id,amount,status,created_at'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ReferralCommission $c) => [
                'id' => $c->id,
                'order_id' => $c->order_id,
                'amount' => (float) $c->amount,
                'platform_fee' => (float) $c->platform_fee,
                'commission_percent' => (float) $c->commission_percent,
                'status' => $c->status,
                'referred_name' => $c->referred?->name,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        $withdrawals = ReferralWithdrawal::query()
            ->where('user_id', $owner->id)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (ReferralWithdrawal $w) => [
                'id' => $w->id,
                'amount' => (float) $w->amount,
                'status' => $w->status,
                'created_at' => $w->created_at?->toIso8601String(),
                'paid_at' => $w->paid_at?->toIso8601String(),
                'failed_reason' => $w->failed_reason,
            ]);

        $pixReady = ReferralWithdrawalService::pixSnapshotFor($owner) !== null;

        return Inertia::render('IndiqueGanhe/Index', [
            'program' => [
                'enabled' => true,
                'commission_percent' => ReferralProgramSettings::commissionPercent(),
                'eligibility_days' => ReferralProgramSettings::eligibilityDays(),
                'min_withdrawal' => ReferralProgramSettings::minWithdrawal(),
                'rules' => ReferralProgramSettings::rulesHtml(),
            ],
            'referral' => [
                'code' => $code,
                'link' => $link,
            ],
            'wallet' => [
                'available_balance' => (float) $wallet->available_balance,
                'lifetime_earned' => (float) $wallet->lifetime_earned,
                'lifetime_withdrawn' => (float) $wallet->lifetime_withdrawn,
            ],
            'referred_users' => $referred,
            'commissions' => $commissions,
            'withdrawals' => $withdrawals,
            'pix_ready' => $pixReady,
            'stats' => [
                'referred_count' => $referred->count(),
                'active_count' => $referred->where('eligible', true)->count(),
            ],
        ]);
    }

    public function ensureCode(Request $request): RedirectResponse
    {
        if (! ReferralProgramSettings::isEnabled()) {
            return back()->with('error', 'Programa desativado.');
        }

        $user = $request->user();
        $owner = $user->isTeam() && $user->tenant_id
            ? (User::query()->find($user->tenant_id) ?? $user)
            : $user;

        ReferralCommissionRecorder::ensureReferralCode($owner);

        return back()->with('success', 'Link de indicação pronto.');
    }

    public function storeWithdrawal(Request $request): RedirectResponse
    {
        if (! ReferralProgramSettings::isEnabled()) {
            return back()->with('error', 'Programa desativado.');
        }

        $user = $request->user();
        $owner = $user->isTeam() && $user->tenant_id
            ? (User::query()->find($user->tenant_id) ?? $user)
            : $user;

        if ((int) $owner->id !== (int) $user->id && ! $user->isTeam()) {
            abort(403);
        }

        // Saque sempre na conta do dono do tenant (equipe age em nome do infoprodutor).
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        ReferralWithdrawalService::request(
            $owner,
            (float) $validated['amount'],
            $validated['notes'] ?? null
        );

        return back()->with('success', 'Saque de indicação solicitado.');
    }
}
