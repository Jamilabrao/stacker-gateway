<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\BuildsMerchantWalletProps;
use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Concerns\ProvidesPlatformGatewayProps;
use App\Http\Controllers\Controller;
use App\Services\AdminWalletAdjustmentService;
use App\Gateways\GatewayRegistry;
use App\Models\CajuPayAccount;
use App\Models\MerchantAdminNote;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\EffectiveMerchantFees;
use App\Services\MerchantWalletAdminBlockService;
use App\Services\MinimumChargeService;
use App\Services\ApiPixAccess;
use App\Services\Med\MedPolicyService;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAuditService;
use App\Services\SalesAchievementsService;
use App\Support\MerchantProfileSnapshot;
use App\Support\PlatformConfigContext;
use App\Support\PercentDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    use BuildsMerchantWalletProps;
    use ProvidesPlatformGatewayProps;
    use RequiresPlatformStepUp;

    public function __construct(
        protected SalesAchievementsService $salesAchievements,
        protected MinimumChargeService $minimumChargeService,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';
        $search = $search !== '' ? $search : null;

        $usersQuery = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR);

        if ($search !== null) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $usersQuery->where(function ($q) use ($like, $search) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('document', 'like', $like);
                if (ctype_digit($search)) {
                    $id = (int) $search;
                    $q->orWhere('id', $id)->orWhere('tenant_id', $id);
                }
            });
        }

        $users = $usersQuery->orderBy('name')->get();

        $tenantIds = $users->pluck('tenant_id')->filter()->unique()->values();
        $userIds = $users->pluck('id');
        $wallets = collect();
        $salesTotals = $this->salesAchievements->getValidSalesTotalsGrouped();
        $adminNotesCounts = collect();
        if (Schema::hasTable('merchant_admin_notes')) {
            $adminNotesCounts = MerchantAdminNote::query()
                ->whereIn('merchant_user_id', $userIds)
                ->selectRaw('merchant_user_id, count(*) as aggregate')
                ->groupBy('merchant_user_id')
                ->pluck('aggregate', 'merchant_user_id');
        }
        if (Schema::hasTable('tenant_wallets')) {
            $wallets = TenantWallet::query()
                ->whereIn('tenant_id', $tenantIds)
                ->get()
                ->keyBy('tenant_id');
        }

        $rows = $users->map(function (User $u) use ($wallets, $salesTotals, $adminNotesCounts) {
            $tid = $u->tenant_id ?? $u->id;
            $tidInt = (int) $tid;
            $w = $wallets->get($tid);
            $medTotal = $tidInt > 0 ? MerchantWalletAdminBlockService::totalMedHoldAmountForTenant($tidInt) : 0.0;

            $walletAdmin = null;
            if ($w && Schema::hasColumn('tenant_wallets', 'admin_withdrawal_blocked')) {
                $walletAdmin = [
                    'admin_withdrawal_blocked' => (bool) $w->admin_withdrawal_blocked,
                    'admin_blocked_amount' => $w->admin_blocked_amount !== null ? (float) $w->admin_blocked_amount : null,
                    'admin_block_until' => $w->admin_block_until?->toIso8601String(),
                    'admin_block_note' => $w->admin_block_note,
                ];
            }

            $chargeLimits = $tidInt > 0 ? $this->chargeLimitsPayloadForTenant($tidInt) : $this->emptyChargeLimitsPayload();

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_url' => $u->avatar ? app(\App\Services\StorageService::class)->url($u->avatar) : null,
                'tenant_id' => $u->tenant_id,
                'person_type' => $u->person_type,
                'document' => $u->document,
                'account_status' => $u->account_status ?? 'approved',
                'merchant_fees' => $u->merchant_fees ?? [],
                'merchant_settlement_overrides' => $u->merchant_settlement_overrides ?? [],
                'merchant_gateway_order' => $u->merchant_gateway_order ?? [],
                'cajupay_account_id' => $u->cajupay_account_id,
                'api_pix_mode' => $tidInt > 0 ? ApiPixAccess::tenantMode($tidInt) : ApiPixAccess::MODE_INHERIT,
                'api_pix_enabled_effective' => $tidInt > 0 ? ApiPixAccess::effectiveForTenant($tidInt) : ApiPixAccess::globalEnabled(),
                'med_zero_enabled' => $tidInt > 0 ? app(MedPolicyService::class)->medZeroForTenant($tidInt) : false,
                'charge_limits' => $chargeLimits,
                'saldo_disponivel' => $w ? (float) $w->available_balance : 0.0,
                'saldo_pix' => $w ? (float) $w->pending_balance : 0.0,
                'vendas_totais' => $salesTotals[$tidInt] ?? 0.0,
                'med_total' => $medTotal,
                'wallet_admin' => $walletAdmin,
                'admin_notes_count' => (int) ($adminNotesCounts[$u->id] ?? 0),
                'totp_enabled' => PlatformTotpService::isEnabledFor($u),
                'created_at' => $u->created_at?->toIso8601String(),
            ];
        });

        $settingsTenantId = PlatformConfigContext::settingsTenantId();
        $editUserId = $request->query('edit');
        $editUserId = is_numeric($editUserId) ? (int) $editUserId : null;

        return Inertia::render('Platform/Users/Index', [
            'users' => $rows,
            'q' => $search,
            'edit_user_id' => $editUserId,
            'gateways' => $this->buildGatewaysListForMerchantPicker(),
            'platform_gateway_order' => $this->buildGatewayOrderForSettings($settingsTenantId),
            'platform_merchant_fees' => $this->formatEffectiveFeesForFrontend(EffectiveMerchantFees::platformDefaults()),
            'platform_charge_limits' => [
                'api_pix_minimum_charge_brl' => $this->minimumChargeService->apiPixMinimumBrl(),
                'platform_minimum_charge_brl' => $this->minimumChargeService->platformMinimumBrl(),
            ],
            'platform_api_pix_enabled' => ApiPixAccess::globalEnabled(),
            'cajupay_accounts' => CajuPayAccount::query()
                ->enabled()
                ->connected()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default'])
                ->map(fn (CajuPayAccount $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'is_default' => $a->is_default,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function show(User $user): Response
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $tenantId = $this->tenantIdForUser($user);

        $adminNotes = [];
        if (Schema::hasTable('merchant_admin_notes')) {
            $adminNotes = MerchantAdminNote::query()
                ->where('merchant_user_id', $user->id)
                ->with('author:id,name')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (MerchantAdminNote $n) => [
                    'id' => $n->id,
                    'body' => $n->body,
                    'created_at' => $n->created_at?->toIso8601String(),
                    'author' => $n->author ? ['id' => $n->author->id, 'name' => $n->author->name] : null,
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Platform/Users/Show', [
            'merchant' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'document' => $user->document,
                'person_type' => $user->person_type,
                'account_status' => $user->account_status ?? 'approved',
                'kyc_status' => Schema::hasColumn('users', 'kyc_status') ? ($user->kyc_status ?? User::KYC_NOT_SUBMITTED) : null,
                'created_at' => $user->created_at?->toIso8601String(),
                'tenant_id' => $tenantId,
                'vendas_totais' => round($this->salesAchievements->getValidSalesTotal($tenantId), 2),
                'totp_enabled' => PlatformTotpService::isEnabledFor($user),
            ],
            'profile' => MerchantProfileSnapshot::forUser($user, maskDocuments: false),
            'wallet' => $this->walletPayloadForTenant($tenantId),
            'withdrawals' => $this->withdrawalsPayloadForTenant($tenantId),
            'wallet_transactions' => $this->walletTransactionsPayloadForTenant($tenantId),
            'wallet_transaction_type_labels' => WalletTransaction::typeLabels(),
            'effective_merchant_fees' => $this->formatEffectiveFeesForFrontend(
                EffectiveMerchantFees::forTenant($tenantId),
                $user->merchant_fees
            ),
            'api_pix_mode' => ApiPixAccess::tenantMode($tenantId),
            'api_pix_enabled_effective' => ApiPixAccess::effectiveForTenant($tenantId),
            'charge_limits' => $this->chargeLimitsPayloadForTenant($tenantId),
            'platform_charge_limits' => [
                'api_pix_minimum_charge_brl' => $this->minimumChargeService->apiPixMinimumBrl(),
                'platform_minimum_charge_brl' => $this->minimumChargeService->platformMinimumBrl(),
            ],
            'platform_api_pix_enabled' => ApiPixAccess::globalEnabled(),
            'admin_notes' => $adminNotes,
        ]);
    }

    public function effectiveFees(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $tenantId = $this->tenantIdForUser($user);
        $draft = $request->input('merchant_fees');
        if (is_array($draft)) {
            $normalized = $this->normalizeMerchantFeesOverrides($draft);
            $fees = EffectiveMerchantFees::fromOverrides($normalized);
        } else {
            $fees = EffectiveMerchantFees::forTenant($tenantId);
        }

        return response()->json([
            'fees' => $this->formatEffectiveFeesForFrontend($fees),
        ]);
    }

    public function adjustBalance(Request $request, User $user, AdminWalletAdjustmentService $adjustmentService): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $this->validatePlatformStepUp($request);

        $validated = $request->validate([
            'totp_code' => ['nullable', 'string', 'max:16'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'direction' => ['required', 'string', 'in:credit,debit'],
            'bucket' => ['nullable', 'string', 'in:pix,card,boleto'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'note.required' => 'Informe o motivo do ajuste.',
            'note.min' => 'O motivo deve ter pelo menos 3 caracteres.',
        ]);

        $amount = round((float) $validated['amount'], 2);
        $delta = $validated['direction'] === 'credit' ? $amount : -$amount;
        $bucket = $validated['bucket'] ?? 'pix';

        $adjustmentService->adjust(
            $this->tenantIdForUser($user),
            $bucket,
            $delta,
            $validated['note'],
            $request
        );

        $redirectTo = $request->input('redirect_to');
        if (is_string($redirectTo) && str_starts_with($redirectTo, '/plataforma/')) {
            return redirect($redirectTo)->with('success', 'Saldo ajustado com sucesso.');
        }

        return redirect()
            ->route('plataforma.usuarios.show', $user)
            ->with('success', 'Saldo ajustado com sucesso.');
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Users/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique' => 'Este e-mail já está em uso.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ]);

        $attrs = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'pending',
        ];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_NOT_SUBMITTED;
        }
        $user = User::create($attrs);

        $user->update(['tenant_id' => $user->id]);

        if (Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->firstOrCreate(
                ['tenant_id' => $user->tenant_id],
                ['available_balance' => 0, 'pending_balance' => 0, 'currency' => 'BRL']
            );
        }

        PlatformAuditService::log('platform.merchant.created', ['user_id' => $user->id], $request);

        return redirect()->route('plataforma.usuarios.index')->with('success', 'Infoprodutor cadastrado com sucesso.');
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $id = $user->id;
        $user->delete();

        PlatformAuditService::log('platform.merchant.deleted', ['user_id' => $id], request());

        return redirect()->route('plataforma.usuarios.index')->with('success', 'Usuário excluído.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $prevAccountStatus = $user->account_status;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'account_status' => ['nullable', 'string', 'in:approved,pending,rejected,suspended,blocked'],
            'admin_withdrawal_blocked' => ['nullable', 'boolean'],
            'admin_blocked_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'admin_block_until' => ['nullable', 'date'],
            'admin_block_note' => ['nullable', 'string', 'max:500'],
            'api_pix_mode' => ['nullable', 'string', 'in:inherit,enabled,disabled'],
            'med_zero_enabled' => ['nullable', 'boolean'],
            'api_pix_minimum_charge_brl' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'platform_minimum_charge_brl' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'use_platform_api_pix_minimum' => ['nullable', 'boolean'],
            'use_platform_platform_minimum' => ['nullable', 'boolean'],
            'cajupay_account_id' => ['nullable', 'integer', 'exists:cajupay_accounts,id'],
        ], [
            'email.unique' => 'Este e-mail já está em uso.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        if ($request->has('account_status') && isset($validated['account_status'])) {
            $newStatus = $validated['account_status'];
            if ($newStatus === 'approved'
                && Schema::hasColumn('users', 'kyc_status')
                && ($user->kyc_status ?? null) !== User::KYC_APPROVED) {
                throw ValidationException::withMessages([
                    'account_status' => 'Aprove a conta somente após verificação KYC em Verificações KYC.',
                ]);
            }
            $user->account_status = $newStatus;
        }

        $all = $request->all();
        if (array_key_exists('merchant_fees', $all)) {
            $user->merchant_fees = $this->normalizeMerchantFeesOverrides(
                is_array($request->input('merchant_fees')) ? $request->input('merchant_fees') : null
            );
        }

        if (array_key_exists('merchant_settlement_overrides', $all)) {
            $user->merchant_settlement_overrides = $this->normalizeMerchantSettlementOverrides(
                is_array($request->input('merchant_settlement_overrides')) ? $request->input('merchant_settlement_overrides') : null
            );
        }

        if (array_key_exists('merchant_gateway_order', $all)) {
            $user->merchant_gateway_order = $this->normalizeMerchantGatewayOrder(
                is_array($request->input('merchant_gateway_order')) ? $request->input('merchant_gateway_order') : null
            );
        }

        if ($request->has('cajupay_account_id')) {
            $accountId = $validated['cajupay_account_id'] ?? null;
            if ($accountId !== null) {
                $account = CajuPayAccount::query()->whereKey($accountId)->enabled()->connected()->first();
                if ($account === null) {
                    throw ValidationException::withMessages([
                        'cajupay_account_id' => 'Conta CajuPay inválida ou indisponível.',
                    ]);
                }
            }
            $user->cajupay_account_id = $accountId;
        }

        $user->save();

        $tenantId = (int) ($user->tenant_id ?? $user->id);
        if ($tenantId > 0) {
            if ($request->has('api_pix_mode') && isset($validated['api_pix_mode'])) {
                $prevMode = ApiPixAccess::tenantMode($tenantId);
                $newMode = $validated['api_pix_mode'];
                if ($prevMode !== $newMode) {
                    ApiPixAccess::setTenantMode($tenantId, $newMode);
                    PlatformAuditService::log('platform.merchant.api_pix_mode', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'from' => $prevMode,
                        'to' => $newMode,
                    ], $request);
                }
            }

            if ($request->has('med_zero_enabled')) {
                $prevMedZero = app(MedPolicyService::class)->medZeroForTenant($tenantId);
                $newMedZero = $request->boolean('med_zero_enabled');
                if ($prevMedZero !== $newMedZero) {
                    app(MedPolicyService::class)->setMedZeroForTenant($tenantId, $newMedZero);
                    PlatformAuditService::log('platform.merchant.med_zero', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'from' => $prevMedZero,
                        'to' => $newMedZero,
                    ], $request);
                }
            }

            if ($this->requestTouchesApiPixMinimum($request)) {
                $prevApi = $this->minimumChargeService->tenantApiPixOverride($tenantId);
                $apiMin = $this->normalizeTenantChargeLimitInput(
                    $request,
                    'api_pix_minimum_charge_brl',
                    'use_platform_api_pix_minimum'
                );
                $this->minimumChargeService->setTenantApiPixOverride($tenantId, $apiMin);
                if ($prevApi !== $apiMin) {
                    PlatformAuditService::log('platform.merchant.charge_limits', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'api_pix_minimum_charge_brl' => $apiMin,
                    ], $request);
                }
            }

            if ($this->requestTouchesPlatformMinimum($request)) {
                $prevPlatform = $this->minimumChargeService->tenantPlatformOverride($tenantId);
                $platformMin = $this->normalizeTenantChargeLimitInput(
                    $request,
                    'platform_minimum_charge_brl',
                    'use_platform_platform_minimum'
                );
                $this->minimumChargeService->setTenantPlatformOverride($tenantId, $platformMin);
                if ($prevPlatform !== $platformMin) {
                    PlatformAuditService::log('platform.merchant.charge_limits', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'platform_minimum_charge_brl' => $platformMin,
                    ], $request);
                }
            }
        }

        if (Schema::hasTable('tenant_wallets') && Schema::hasColumn('tenant_wallets', 'admin_withdrawal_blocked')) {
            if ($tenantId > 0) {
                $wallet = TenantWallet::query()->firstOrCreate(
                    ['tenant_id' => $tenantId],
                    ['available_balance' => 0, 'pending_balance' => 0, 'currency' => 'BRL']
                );
                $wallet->admin_withdrawal_blocked = $request->boolean('admin_withdrawal_blocked');
                $amt = $request->input('admin_blocked_amount');
                $wallet->admin_blocked_amount = ($amt === null || $amt === '') ? null : round((float) $amt, 2);
                $until = $request->input('admin_block_until');
                $wallet->admin_block_until = ($until === null || $until === '') ? null : $until;
                $wallet->admin_block_note = $request->input('admin_block_note') ?: null;
                if ($wallet->isDirty(['admin_withdrawal_blocked', 'admin_blocked_amount', 'admin_block_until', 'admin_block_note'])) {
                    $wallet->save();
                    PlatformAuditService::log('platform.merchant.wallet_admin_block', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'admin_withdrawal_blocked' => $wallet->admin_withdrawal_blocked,
                        'admin_blocked_amount' => $wallet->admin_blocked_amount,
                        'admin_block_until' => $wallet->admin_block_until?->toIso8601String(),
                    ], $request);
                }
            }
        }

        if (($prevAccountStatus ?? null) !== ($user->account_status ?? null)) {
            PlatformAuditService::log('platform.merchant.account_status', [
                'user_id' => $user->id,
                'from' => $prevAccountStatus,
                'to' => $user->account_status,
            ], $request);
        }

        PlatformAuditService::log('platform.merchant.updated', ['user_id' => $user->id], $request);

        return redirect()->route('plataforma.usuarios.index')->with('success', 'Usuário atualizado.');
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function normalizeMerchantFeesOverrides(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach (['pix', 'api_pix', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'] as $key) {
            $block = $raw[$key] ?? null;
            if (! is_array($block)) {
                continue;
            }
            $row = [];
            if (array_key_exists('percent', $block) && $block['percent'] !== '' && $block['percent'] !== null) {
                $p = (float) $block['percent'];
                if ($p < 0 || $p > 100) {
                    throw ValidationException::withMessages([
                        "merchant_fees.$key.percent" => 'O percentual deve estar entre 0 e 100.',
                    ]);
                }
                $row['percent'] = PercentDecimal::toFloat(PercentDecimal::normalize($p));
            }
            if (array_key_exists('fixed', $block) && $block['fixed'] !== '' && $block['fixed'] !== null) {
                $f = (float) $block['fixed'];
                if ($f < 0 || $f > 999999) {
                    throw ValidationException::withMessages([
                        "merchant_fees.$key.fixed" => 'Valor fixo inválido.',
                    ]);
                }
                $row['fixed'] = round($f, 2);
            }
            if ($row !== []) {
                $out[$key] = $row;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function normalizeMerchantSettlementOverrides(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach (\App\Services\EffectiveSettlementRules::SETTLEMENT_METHOD_KEYS as $key) {
            $block = $raw[$key] ?? null;
            if (! is_array($block)) {
                continue;
            }
            $row = [];
            if (array_key_exists('days_to_available', $block) && $block['days_to_available'] !== '' && $block['days_to_available'] !== null) {
                $row['days_to_available'] = max(0, min(365, (int) $block['days_to_available']));
            }
            if (array_key_exists('reserve_percent', $block) && $block['reserve_percent'] !== '' && $block['reserve_percent'] !== null) {
                $row['reserve_percent'] = round(min(100, max(0, (float) $block['reserve_percent'])), 2);
            }
            if (array_key_exists('reserve_hold_days', $block) && $block['reserve_hold_days'] !== '' && $block['reserve_hold_days'] !== null) {
                $row['reserve_hold_days'] = max(0, min(365, (int) $block['reserve_hold_days']));
            }
            if ($row !== []) {
                $out[$key] = $row;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function normalizeMerchantGatewayOrder(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach (['pix', 'card', 'boleto', 'pix_auto'] as $method) {
            $list = $raw[$method] ?? null;
            if (! is_array($list)) {
                continue;
            }
            $slugs = [];
            foreach ($list as $s) {
                if (is_string($s) && preg_match('/^[a-z0-9_-]+$/', $s)) {
                    $slugs[] = $s;
                }
            }
            $slugs = GatewayRegistry::filterSlugsToAllowedAcquirers($slugs);
            if ($slugs !== []) {
                $out[$method] = $slugs;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, array{percent: float, fixed: float}>  $fees
     * @param  array<string, mixed>|null  $rawOverrides
     * @return list<array{key: string, label: string, percent: float, fixed: float, has_override: bool}>
     */
    private function formatEffectiveFeesForFrontend(array $fees, ?array $rawOverrides = null): array
    {
        $labels = [
            'pix' => 'PIX (checkout)',
            'api_pix' => 'PIX (API)',
            'card' => 'Cartão',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'boleto' => 'Boleto',
            'withdrawal' => 'Saque',
        ];

        $rows = [];
        foreach (['pix', 'api_pix', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'] as $key) {
            $block = $fees[$key] ?? ['percent' => 0.0, 'fixed' => 0.0];
            $overrideBlock = is_array($rawOverrides) ? ($rawOverrides[$key] ?? null) : null;
            $hasOverride = is_array($overrideBlock) && (
                (array_key_exists('percent', $overrideBlock) && $overrideBlock['percent'] !== '' && $overrideBlock['percent'] !== null)
                || (array_key_exists('fixed', $overrideBlock) && $overrideBlock['fixed'] !== '' && $overrideBlock['fixed'] !== null)
            );
            $rows[] = [
                'key' => $key,
                'label' => $labels[$key],
                'percent' => round((float) ($block['percent'] ?? 0), 4),
                'fixed' => round((float) ($block['fixed'] ?? 0), 2),
                'has_override' => $hasOverride,
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     api_pix_minimum_charge_brl: float|null,
     *     platform_minimum_charge_brl: float|null,
     *     api_pix_minimum_effective_brl: float,
     *     platform_minimum_effective_brl: float
     * }
     */
    private function chargeLimitsPayloadForTenant(int $tenantId): array
    {
        return [
            'api_pix_minimum_charge_brl' => $this->minimumChargeService->tenantApiPixOverride($tenantId),
            'platform_minimum_charge_brl' => $this->minimumChargeService->tenantPlatformOverride($tenantId),
            'api_pix_minimum_effective_brl' => $this->minimumChargeService->apiPixMinimumBrlForTenant($tenantId),
            'platform_minimum_effective_brl' => $this->minimumChargeService->platformMinimumBrlForTenant($tenantId),
        ];
    }

    /**
     * @return array{
     *     api_pix_minimum_charge_brl: null,
     *     platform_minimum_charge_brl: null,
     *     api_pix_minimum_effective_brl: float,
     *     platform_minimum_effective_brl: float
     * }
     */
    private function emptyChargeLimitsPayload(): array
    {
        return [
            'api_pix_minimum_charge_brl' => null,
            'platform_minimum_charge_brl' => null,
            'api_pix_minimum_effective_brl' => $this->minimumChargeService->apiPixMinimumBrl(),
            'platform_minimum_effective_brl' => $this->minimumChargeService->platformMinimumBrl(),
        ];
    }

    private function requestTouchesApiPixMinimum(Request $request): bool
    {
        return $request->hasAny(['api_pix_minimum_charge_brl', 'use_platform_api_pix_minimum']);
    }

    private function requestTouchesPlatformMinimum(Request $request): bool
    {
        return $request->hasAny(['platform_minimum_charge_brl', 'use_platform_platform_minimum']);
    }

    private function normalizeTenantChargeLimitInput(Request $request, string $field, string $inheritFlag): ?float
    {
        if ($request->boolean($inheritFlag)) {
            return null;
        }

        if (! $request->has($field)) {
            return null;
        }

        $raw = $request->input($field);
        if ($raw === null || $raw === '') {
            return null;
        }

        return round(max(0, (float) $raw), 2);
    }
}
