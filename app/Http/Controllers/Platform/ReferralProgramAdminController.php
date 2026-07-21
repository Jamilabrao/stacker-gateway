<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Models\ReferralCommission;
use App\Models\ReferralWithdrawal;
use App\Models\User;
use App\Services\ReferralAttributionService;
use App\Services\ReferralWithdrawalService;
use App\Support\ReferralProgramSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ReferralProgramAdminController extends Controller
{
    use RequiresPlatformStepUp;

    public function index(Request $request): Response
    {
        $tab = (string) $request->query('tab', 'config');
        if (! in_array($tab, ['config', 'indicacoes', 'saques'], true)) {
            $tab = 'config';
        }

        $referrals = [];
        if ($tab === 'indicacoes' && Schema::hasColumn('users', 'referred_by_user_id')) {
            $referrals = User::query()
                ->whereNotNull('referred_by_user_id')
                ->with(['referredBy:id,name,email'])
                ->orderByDesc('referred_at')
                ->limit(200)
                ->get(['id', 'name', 'email', 'account_status', 'referred_by_user_id', 'referred_at'])
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
                        'referrer' => $u->referredBy ? [
                            'id' => $u->referredBy->id,
                            'name' => $u->referredBy->name,
                            'email' => $u->referredBy->email,
                        ] : null,
                        'earned' => round($earned, 2),
                    ];
                });
        }

        $withdrawals = [];
        if ($tab === 'saques' && Schema::hasTable('referral_withdrawals')) {
            $withdrawals = ReferralWithdrawal::query()
                ->with(['user:id,name,email'])
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (ReferralWithdrawal $w) => [
                    'id' => $w->id,
                    'amount' => (float) $w->amount,
                    'status' => $w->status,
                    'created_at' => $w->created_at?->toIso8601String(),
                    'paid_at' => $w->paid_at?->toIso8601String(),
                    'failed_reason' => $w->failed_reason,
                    'notes' => $w->notes,
                    'pix_snapshot' => $w->pix_snapshot,
                    'user' => $w->user ? [
                        'id' => $w->user->id,
                        'name' => $w->user->name,
                        'email' => $w->user->email,
                    ] : null,
                ]);
        }

        $sellers = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email']);

        return Inertia::render('Platform/IndiqueGanhe/Index', [
            'tab' => $tab,
            'settings' => ReferralProgramSettings::forSettingsForm(),
            'referrals' => $referrals,
            'withdrawals' => $withdrawals,
            'sellers' => $sellers,
            'pending_withdrawals_count' => Schema::hasTable('referral_withdrawals')
                ? ReferralWithdrawal::query()->where('status', ReferralWithdrawal::STATUS_PENDING)->count()
                : 0,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->validatePlatformStepUp($request, false, 'plataforma.indique-ganhe.index');

        $validated = $request->validate([
            'enabled' => ['nullable'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'eligibility_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'rules_html' => ['nullable', 'string', 'max:20000'],
            'min_withdrawal' => ['required', 'numeric', 'min:0.01'],
            'cookie_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        ReferralProgramSettings::persistFromValidated($validated);

        return redirect()
            ->route('plataforma.indique-ganhe.index', ['tab' => 'config'])
            ->with('success', 'Configurações do Indique e Ganhe salvas.');
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->validatePlatformStepUp($request, false, 'plataforma.indique-ganhe.index');

        $validated = $request->validate([
            'referred_user_id' => ['required', 'integer', 'exists:users,id'],
            'referred_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'force' => ['nullable', 'boolean'],
        ]);

        $referred = User::query()->findOrFail((int) $validated['referred_user_id']);
        $referrerId = isset($validated['referred_by_user_id'])
            ? (int) $validated['referred_by_user_id']
            : null;

        ReferralAttributionService::assignReferrer(
            $referred,
            $referrerId,
            (bool) ($validated['force'] ?? false)
        );

        return redirect()
            ->route('plataforma.indique-ganhe.index', ['tab' => 'indicacoes'])
            ->with('success', 'Indicação atualizada.');
    }

    public function approveWithdrawal(Request $request, ReferralWithdrawal $withdrawal): RedirectResponse
    {
        $this->validatePlatformStepUp($request, true, 'plataforma.indique-ganhe.index');

        ReferralWithdrawalService::markPaid($withdrawal, $request->user(), $request->input('notes'));

        return redirect()
            ->route('plataforma.indique-ganhe.index', ['tab' => 'saques'])
            ->with('success', 'Saque de indicação marcado como pago.');
    }

    public function rejectWithdrawal(Request $request, ReferralWithdrawal $withdrawal): RedirectResponse
    {
        $this->validatePlatformStepUp($request, true, 'plataforma.indique-ganhe.index');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        ReferralWithdrawalService::reject(
            $withdrawal,
            $request->user(),
            $validated['reason'] ?? 'Rejeitado pela plataforma'
        );

        return redirect()
            ->route('plataforma.indique-ganhe.index', ['tab' => 'saques'])
            ->with('success', 'Saque de indicação rejeitado e saldo devolvido.');
    }
}
