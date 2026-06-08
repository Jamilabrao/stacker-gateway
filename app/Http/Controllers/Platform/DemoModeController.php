<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DemoMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoModeController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $adminCandidates = User::query()
            ->where('role', User::ROLE_PLATFORM_ADMIN)
            ->whereNull('tenant_id')
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'email']);

        $sellerCandidates = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNotNull('tenant_id')
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'email']);

        return response()->json([
            ...DemoMode::configForAdminForm(),
            'admin_candidates' => $adminCandidates,
            'seller_candidates' => $sellerCandidates,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if (! DemoMode::canConfigure()) {
            return response()->json([
                'message' => 'Modo demonstração ativo. Desative GETFY_DEMO_MODE no .env para alterar esta configuração.',
            ], 403);
        }

        $validated = $request->validate([
            'admin_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'seller_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            DemoMode::saveUserIds(
                isset($validated['admin_user_id']) ? (int) $validated['admin_user_id'] : null,
                isset($validated['seller_user_id']) ? (int) $validated['seller_user_id'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Configuração demo salva.',
            ...DemoMode::configForAdminForm(),
        ]);
    }

    public function provision(Request $request): JsonResponse
    {
        if (! DemoMode::canConfigure()) {
            return response()->json([
                'message' => 'Modo demonstração ativo. Desative GETFY_DEMO_MODE no .env para provisionar contas.',
            ], 403);
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = is_string($host) && $host !== '' ? $host : 'demo.local';

        $adminEmail = 'demo-admin@'.$domain;
        $sellerEmail = 'demo-vendedor@'.$domain;
        $password = Str::password(32);

        $admin = User::query()->where('email', $adminEmail)->first();
        if ($admin) {
            $admin->update([
                'name' => 'Admin Demo',
                'password' => Hash::make($password),
                'role' => User::ROLE_PLATFORM_ADMIN,
                'tenant_id' => null,
            ]);
        } else {
            $admin = User::create([
                'name' => 'Admin Demo',
                'email' => $adminEmail,
                'password' => Hash::make($password),
                'role' => User::ROLE_PLATFORM_ADMIN,
                'tenant_id' => null,
            ]);
        }

        $seller = User::query()->where('email', $sellerEmail)->first();
        if ($seller) {
            $seller->update([
                'name' => 'Vendedor Demo',
                'password' => Hash::make($password),
                'role' => User::ROLE_INFOPRODUTOR,
            ]);
            if ($seller->tenant_id === null) {
                $seller->update(['tenant_id' => $seller->id]);
            }
        } else {
            $seller = User::create([
                'name' => 'Vendedor Demo',
                'email' => $sellerEmail,
                'password' => Hash::make($password),
                'role' => User::ROLE_INFOPRODUTOR,
                'tenant_id' => null,
            ]);
            $seller->update(['tenant_id' => $seller->id]);
        }

        DemoMode::saveUserIds((int) $admin->id, (int) $seller->id);

        return response()->json([
            'success' => true,
            'message' => 'Contas demo criadas/atualizadas. Acesso apenas pelos botões na tela de login.',
            ...DemoMode::configForAdminForm(),
            'provisioned' => [
                'admin_email' => $adminEmail,
                'seller_email' => $sellerEmail,
            ],
        ]);
    }
}
