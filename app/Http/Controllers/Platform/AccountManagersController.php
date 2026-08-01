<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AccountManager;
use App\Models\AccountManagerAssignment;
use App\Models\User;
use App\Services\AccountManagerAssignmentService;
use App\Services\PlatformAuditService;
use App\Services\StorageService;
use App\Support\SellerPanelSupportSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class AccountManagersController extends Controller
{
    public function __construct(
        protected AccountManagerAssignmentService $assignments,
    ) {}

    public function index(Request $request): Response
    {
        if (! AccountManagerAssignmentService::ready()) {
            return Inertia::render('Platform/AccountManagers/Index', [
                'managers' => new LengthAwarePaginator([], 0, 25, 1),
                'filters' => ['q' => null, 'status' => 'all', 'per_page' => 25],
                'ready' => false,
            ]);
        }

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $query = AccountManager::query()->orderBy('name');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString()->through(
            fn (AccountManager $m) => $this->assignments->adminPayload($m)
        );

        return Inertia::render('Platform/AccountManagers/Index', [
            'managers' => $paginator,
            'filters' => [
                'q' => $q !== '' ? $q : null,
                'status' => $status,
                'per_page' => $perPage,
            ],
            'ready' => true,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/AccountManagers/Form', [
            'manager' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedManager($request);
        $manager = AccountManager::query()->create($data);

        if ($request->hasFile('avatar')) {
            $this->storeAvatar($request, $manager);
        }

        PlatformAuditService::log('account_managers.created', [
            'account_manager_id' => $manager->id,
            'name' => $manager->name,
        ], $request);

        return redirect()
            ->route('plataforma.gerentes-conta.show', $manager)
            ->with('success', 'Gerente de conta cadastrado.');
    }

    public function show(Request $request, AccountManager $gerente): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $merchantsQuery = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where('account_manager_id', $gerente->id)
            ->orderBy('name');

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $merchantsQuery->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
        }

        $merchants = $merchantsQuery->paginate($perPage)->withQueryString()->through(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'account_status' => $u->account_status ?? 'approved',
            'created_at' => $u->created_at?->toIso8601String(),
        ]);

        $activeManagers = AccountManager::query()
            ->active()
            ->where('id', '!=', $gerente->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AccountManager $m) => ['id' => $m->id, 'name' => $m->name])
            ->values()
            ->all();

        $history = AccountManagerAssignment::query()
            ->where('account_manager_id', $gerente->id)
            ->with(['merchant:id,name,email', 'assignedByUser:id,name'])
            ->orderByDesc('assigned_at')
            ->limit(30)
            ->get()
            ->map(fn (AccountManagerAssignment $a) => [
                'id' => $a->id,
                'merchant' => $a->merchant ? ['id' => $a->merchant->id, 'name' => $a->merchant->name, 'email' => $a->merchant->email] : null,
                'assigned_by' => $a->assignedByUser?->name,
                'assigned_at' => $a->assigned_at?->toIso8601String(),
                'ended_at' => $a->ended_at?->toIso8601String(),
                'reason' => $a->reason,
                'source' => $a->source,
            ])
            ->values()
            ->all();

        return Inertia::render('Platform/AccountManagers/Show', [
            'manager' => $this->assignments->adminPayload($gerente),
            'merchants' => $merchants,
            'filters' => ['q' => $q !== '' ? $q : null, 'per_page' => $perPage],
            'active_managers' => $activeManagers,
            'history' => $history,
        ]);
    }

    public function edit(AccountManager $gerente): Response
    {
        return Inertia::render('Platform/AccountManagers/Form', [
            'manager' => $this->assignments->adminPayload($gerente),
        ]);
    }

    public function update(Request $request, AccountManager $gerente): RedirectResponse
    {
        $data = $this->validatedManager($request, $gerente);
        $gerente->fill($data)->save();

        if ($request->boolean('remove_avatar') && $gerente->avatar) {
            $storage = app(StorageService::class);
            if ($storage->exists($gerente->avatar)) {
                $storage->delete($gerente->avatar);
            }
            $gerente->forceFill(['avatar' => null])->save();
        } elseif ($request->hasFile('avatar')) {
            $this->storeAvatar($request, $gerente);
        }

        PlatformAuditService::log('account_managers.updated', [
            'account_manager_id' => $gerente->id,
        ], $request);

        return redirect()
            ->route('plataforma.gerentes-conta.show', $gerente)
            ->with('success', 'Gerente atualizado.');
    }

    public function updateActive(Request $request, AccountManager $gerente): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required'],
            'deactivate_action' => ['nullable', 'string', 'in:keep,transfer,distribute'],
            'target_manager_id' => ['nullable', 'integer', 'exists:account_managers,id'],
            'distribute_manager_ids' => ['nullable', 'array'],
            'distribute_manager_ids.*' => ['integer', 'exists:account_managers,id'],
            'distribute_mode' => ['nullable', 'string', 'in:least_load,equal,random'],
        ]);

        $active = $request->boolean('is_active');
        $count = $gerente->activeMerchantsCount();

        if (! $active && $count > 0) {
            $action = $validated['deactivate_action'] ?? null;
            if (! in_array($action, ['keep', 'transfer', 'distribute'], true)) {
                return back()->with('error', 'Este gerente possui infoprodutores vinculados. Escolha manter, transferir ou distribuir a carteira.');
            }

            try {
                if ($action === 'transfer') {
                    $target = AccountManager::query()->findOrFail((int) $validated['target_manager_id']);
                    $ids = User::query()
                        ->where('role', User::ROLE_INFOPRODUTOR)
                        ->where('account_manager_id', $gerente->id)
                        ->orderBy('id')
                        ->limit(AccountManagerAssignmentService::SYNC_CHUNK_LIMIT)
                        ->pluck('id')
                        ->all();
                    $this->assignments->transfer(
                        $gerente,
                        $target,
                        $ids,
                        $request->user(),
                        AccountManagerAssignment::SOURCE_MANAGER_DEACTIVATION,
                        'Desativação do gerente',
                        $request
                    );
                } elseif ($action === 'distribute') {
                    $ids = User::query()
                        ->where('role', User::ROLE_INFOPRODUTOR)
                        ->where('account_manager_id', $gerente->id)
                        ->orderBy('id')
                        ->limit(AccountManagerAssignmentService::SYNC_CHUNK_LIMIT)
                        ->pluck('id')
                        ->all();
                    $managerIds = array_values(array_map('intval', $validated['distribute_manager_ids'] ?? []));
                    $this->assignments->distribute(
                        $ids,
                        $managerIds,
                        $validated['distribute_mode'] ?? 'least_load',
                        $request->user(),
                        false,
                        'Desativação do gerente',
                        $request
                    );
                }
            } catch (InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        $gerente->forceFill(['is_active' => $active])->save();

        PlatformAuditService::log($active ? 'account_managers.activated' : 'account_managers.deactivated', [
            'account_manager_id' => $gerente->id,
            'merchants_count' => $count,
            'deactivate_action' => $validated['deactivate_action'] ?? null,
        ], $request);

        return back()->with('success', $active ? 'Gerente ativado.' : 'Gerente desativado.');
    }

    public function destroy(Request $request, AccountManager $gerente): RedirectResponse
    {
        if ($gerente->activeMerchantsCount() > 0) {
            return back()->with('error', 'Não é possível excluir: há infoprodutores vinculados. Desative ou transfira a carteira.');
        }
        if (AccountManagerAssignment::query()->where('account_manager_id', $gerente->id)->exists()) {
            return back()->with('error', 'Não é possível excluir: existe histórico de atribuições. Desative o gerente.');
        }

        if ($gerente->avatar) {
            $storage = app(StorageService::class);
            if ($storage->exists($gerente->avatar)) {
                $storage->delete($gerente->avatar);
            }
        }

        $id = $gerente->id;
        $gerente->delete();

        PlatformAuditService::log('account_managers.deleted', ['account_manager_id' => $id], $request);

        return redirect()
            ->route('plataforma.gerentes-conta.index')
            ->with('success', 'Gerente excluído.');
    }

    public function transfer(Request $request, AccountManager $gerente): RedirectResponse
    {
        $validated = $request->validate([
            'target_manager_id' => ['required', 'integer', 'exists:account_managers,id'],
            'merchant_ids' => ['nullable', 'array'],
            'merchant_ids.*' => ['integer', 'exists:users,id'],
            'transfer_all' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $target = AccountManager::query()->findOrFail((int) $validated['target_manager_id']);

        if ($request->boolean('transfer_all')) {
            $ids = User::query()
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->where('account_manager_id', $gerente->id)
                ->orderBy('id')
                ->limit(AccountManagerAssignmentService::SYNC_CHUNK_LIMIT)
                ->pluck('id')
                ->all();
        } else {
            $ids = array_values(array_map('intval', $validated['merchant_ids'] ?? []));
        }

        try {
            $result = $this->assignments->transfer(
                $gerente,
                $target,
                $ids,
                $request->user(),
                AccountManagerAssignment::SOURCE_BULK_TRANSFER,
                $validated['reason'] ?? null,
                $request
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            "Transferência concluída: {$result['processed']} movidos".($result['skipped'] ? ", {$result['skipped']} ignorados" : '').'.'
        );
    }

    public function distributePreview(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'merchant_ids' => ['required', 'array', 'min:1'],
            'merchant_ids.*' => ['integer', 'exists:users,id'],
            'manager_ids' => ['required', 'array', 'min:1'],
            'manager_ids.*' => ['integer', 'exists:account_managers,id'],
            'mode' => ['required', 'string', 'in:least_load,equal,random'],
        ]);

        try {
            $result = $this->assignments->distribute(
                array_map('intval', $validated['merchant_ids']),
                array_map('intval', $validated['manager_ids']),
                $validated['mode'],
                $request->user(),
                true
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function distribute(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'merchant_ids' => ['required', 'array', 'min:1'],
            'merchant_ids.*' => ['integer', 'exists:users,id'],
            'manager_ids' => ['required', 'array', 'min:1'],
            'manager_ids.*' => ['integer', 'exists:account_managers,id'],
            'mode' => ['required', 'string', 'in:least_load,equal,random'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->assignments->distribute(
                array_map('intval', $validated['merchant_ids']),
                array_map('intval', $validated['manager_ids']),
                $validated['mode'],
                $request->user(),
                false,
                $validated['reason'] ?? null,
                $request
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Distribuição concluída: {$result['processed']} infoprodutores.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedManager(Request $request, ?AccountManager $existing = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('account_managers', 'email')->ignore($existing?->id),
            ],
            'phone' => ['required', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'show_email' => ['nullable', 'boolean'],
            'show_phone' => ['nullable', 'boolean'],
            'show_whatsapp' => ['nullable', 'boolean'],
            'show_photo' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'avatar' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $phone = SellerPanelSupportSettings::normalizeWhatsappNumber($validated['phone'] ?? '');
        if ($phone === '' || strlen($phone) < 12) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phone' => 'Informe um WhatsApp válido com DDD (10 ou 11 dígitos).',
            ]);
        }

        return [
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'phone' => $phone,
            'is_active' => $request->boolean('is_active', true),
            'show_email' => $request->boolean('show_email', true),
            'show_phone' => $request->boolean('show_phone', true),
            'show_whatsapp' => $request->boolean('show_whatsapp', true),
            'show_photo' => $request->boolean('show_photo', true),
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function storeAvatar(Request $request, AccountManager $manager): void
    {
        $storage = app(StorageService::class);
        if ($manager->avatar && $storage->exists($manager->avatar)) {
            $storage->delete($manager->avatar);
        }
        $path = $storage->putFile('account-managers', $request->file('avatar'));
        $manager->forceFill(['avatar' => $path])->save();
    }
}
