<?php

namespace App\Services;

use App\Events\ProductDeleted;
use App\Models\Order;
use App\Models\Product;
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
