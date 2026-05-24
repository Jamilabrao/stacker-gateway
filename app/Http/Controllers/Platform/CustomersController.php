<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformAdminDeletionService;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CustomersController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';
        $search = $search !== '' ? $search : null;

        $query = User::query()
            ->whereHas('orders', fn ($q) => $q->where('status', 'completed'))
            ->withCount(['orders as purchases_count' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('id');

        if ($search !== null) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
        }

        $users = $query->paginate(30)->withQueryString();

        return Inertia::render('Platform/Customers/Index', [
            'users' => $users,
            'q' => $search,
            'pageTitle' => 'Clientes',
        ]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $redirectParams = $request->query('q') ? ['q' => $request->query('q')] : [];
        $userId = $user->id;

        try {
            PlatformAdminDeletionService::deleteCustomer($user);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.clientes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.clientes.index', $redirectParams)
                ->with('error', 'Não foi possível excluir o cliente: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.customer.deleted', ['user_id' => $userId], $request);

        return redirect()->route('plataforma.clientes.index', $redirectParams)
            ->with('success', 'Cliente excluído. Os pedidos antigos permanecem no sistema sem vínculo à conta.');
    }

    public function destroyOrderHistory(Request $request, User $user): RedirectResponse
    {
        $redirectParams = $request->query('q') ? ['q' => $request->query('q')] : [];
        $userId = $user->id;

        try {
            $count = PlatformAdminDeletionService::deleteCustomerOrderHistory($user);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.clientes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.clientes.index', $redirectParams)
                ->with('error', 'Não foi possível excluir o histórico: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.customer.order_history_deleted', [
            'user_id' => $userId,
            'orders_deleted' => $count,
        ], $request);

        return redirect()->route('plataforma.clientes.index', $redirectParams)
            ->with('success', $count > 0
                ? "Histórico removido: {$count} pedido(s) excluído(s)."
                : 'Este cliente não tinha pedidos para excluir.');
    }
}
