<?php

namespace App\Services;

use App\Events\ProductDeleted;
use App\Models\MerchantAdminNote;
use App\Models\Order;
use App\Models\Product;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PlatformAdminDeletionService
{
    public static function deleteProduct(Product $product): void
    {
        event(new ProductDeleted($product));

        $storage = app(StorageService::class);
        if ($product->image && $storage->exists($product->image)) {
            $storage->delete($product->image);
        }

        $product->delete();
    }

    /**
     * Remove conta de comprador. Pedidos permanecem (user_id anulado pela FK).
     */
    public static function deleteCustomer(User $user): void
    {
        self::assertDeletableBuyerAccount($user);

        $user->products()->detach();
        $user->delete();
    }

    public static function merchantDeletionBlockReason(User $user, bool $force = false): ?string
    {
        if (! $user->isInfoprodutor()) {
            return 'Conta não é de infoprodutor.';
        }

        $tenantId = (int) ($user->tenant_id ?? $user->id);

        if (Schema::hasTable('tenant_wallets')) {
            $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->first();
            if ($wallet !== null) {
                $balance = (float) $wallet->available_balance + (float) $wallet->pending_balance;
                if ($balance > 0.009) {
                    return 'Carteira com saldo disponível ou pendente.';
                }
            }
        }

        if (! $force && Order::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'disputed'])
            ->exists()) {
            return 'Existem pedidos pagos ou em disputa vinculados a esta conta.';
        }

        return null;
    }

    public static function deleteMerchant(User $user, bool $force = false): void
    {
        $reason = self::merchantDeletionBlockReason($user, $force);
        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }

        $tenantId = (int) ($user->tenant_id ?? $user->id);

        DB::transaction(function () use ($user, $tenantId) {
            User::query()
                ->where('tenant_id', $user->id)
                ->where('id', '!=', $user->id)
                ->where('role', User::ROLE_TEAM)
                ->delete();

            if (Schema::hasTable('merchant_admin_notes')) {
                MerchantAdminNote::query()->where('merchant_user_id', $user->id)->delete();
            }

            if (Schema::hasTable('tenant_wallets')) {
                TenantWallet::query()->where('tenant_id', $tenantId)->delete();
            }

            $user->delete();
        });
    }

    /**
     * Exclui todos os pedidos do comprador (histórico de transações).
     *
     * @return int Quantidade de pedidos removidos
     */
    public static function deleteCustomerOrderHistory(User $user): int
    {
        self::assertDeletableBuyerAccount($user);

        $orders = $user->orders()->orderBy('id')->get();
        $count = 0;

        foreach ($orders as $order) {
            self::deleteOrder($order);
            $count++;
        }

        return $count;
    }

    public static function deleteOrder(Order $order): void
    {
        if (in_array($order->status, ['completed', 'disputed'], true)) {
            throw new InvalidArgumentException(
                'Pedidos pagos ou em MED não podem ser excluídos. Reembolse o pedido antes de remover do histórico.'
            );
        }

        if ($order->status === 'pending') {
            try {
                PlatformOrderAdminService::cancelPending($order->fresh());
            } catch (InvalidArgumentException) {
                // Já não está pendente; segue para exclusão.
            }
            $order->refresh();
        }

        self::assertNoActiveSaleCredits($order);

        DB::transaction(function () use ($order) {
            if (Schema::hasTable('wallet_transactions')) {
                WalletTransaction::query()->where('order_id', $order->id)->delete();
            }
            $order->delete();
        });
    }

    /**
     * Exclusão em lote (tudo ou nada) restrita a pedidos ainda pendentes.
     *
     * @param  list<int>  $orderIds
     * @return list<int> IDs excluídos
     */
    public static function deletePendingOrdersAllOrNothing(array $orderIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));

        if ($ids === []) {
            throw new InvalidArgumentException('Selecione ao menos uma transação pendente.');
        }

        if (count($ids) > 100) {
            throw new InvalidArgumentException('É possível excluir no máximo 100 transações por operação.');
        }

        return DB::transaction(function () use ($ids) {
            $orders = Order::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count($ids)) {
                throw new InvalidArgumentException(
                    'Uma ou mais transações não foram encontradas.'
                );
            }

            foreach ($orders as $order) {
                if ($order->status !== 'pending') {
                    throw new InvalidArgumentException(
                        'Uma ou mais transações foram atualizadas e não podem mais ser excluídas porque não estão mais pendentes.'
                    );
                }
            }

            $deleted = [];
            foreach ($orders as $order) {
                self::deleteOrder($order);
                $deleted[] = (int) $order->id;
            }

            return $deleted;
        });
    }

    private static function assertDeletableBuyerAccount(User $user): void
    {
        if ($user->isInfoprodutor() || $user->isTeam() || $user->role === User::ROLE_PLATFORM_ADMIN) {
            throw new InvalidArgumentException('Não é possível excluir contas de infoprodutor, equipe ou administrador da plataforma.');
        }
    }

    private static function assertNoActiveSaleCredits(Order $order): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        $hasActiveCredit = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING])
            ->get()
            ->contains(function (WalletTransaction $line) {
                if ($line->type === WalletTransaction::TYPE_CREDIT_SALE) {
                    return true;
                }
                $meta = is_array($line->meta) ? $line->meta : [];

                return empty($meta['released_at']);
            });

        if ($hasActiveCredit) {
            throw new InvalidArgumentException(
                'Este pedido ainda tem crédito ativo na carteira. Reembolse antes de excluir.'
            );
        }
    }
}
