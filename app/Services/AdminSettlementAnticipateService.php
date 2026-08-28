<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdminSettlementAnticipateService
{
    /** @var list<string> */
    public const BLOCKED_ORDER_STATUSES = ['disputed', 'refunded', 'refund_pending', 'cancelled'];

    public function anticipate(WalletTransaction $tx, User $actor): WalletTransaction
    {
        return DB::transaction(function () use ($tx, $actor) {
            $locked = WalletTransaction::query()->whereKey($tx->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new InvalidArgumentException('Movimentação não encontrada.');
            }

            $this->assertCanAnticipate($locked);

            if (! SettlementReleaseService::releaseOne($locked)) {
                throw new InvalidArgumentException('Não foi possível antecipar este saldo. Verifique se o valor ainda está em liquidação.');
            }

            $now = now();
            $note = self::anticipationNote($now);

            $locked->refresh();
            $pendingMeta = is_array($locked->meta) ? $locked->meta : [];
            $pendingMeta['note'] = $note;
            $pendingMeta['anticipated_at'] = $now->toIso8601String();
            $pendingMeta['anticipated_by_user_id'] = $actor->id;
            $locked->meta = $pendingMeta;
            $locked->save();

            $saleId = (int) ($pendingMeta['released_to_wallet_transaction_id'] ?? 0);
            if ($saleId > 0) {
                $sale = WalletTransaction::query()->whereKey($saleId)->lockForUpdate()->first();
                if ($sale !== null) {
                    $saleMeta = is_array($sale->meta) ? $sale->meta : [];
                    $saleMeta['note'] = $note;
                    $saleMeta['anticipated_at'] = $now->toIso8601String();
                    $saleMeta['anticipated_by_user_id'] = $actor->id;
                    $sale->meta = $saleMeta;
                    $sale->save();
                }
            }

            return $locked->fresh();
        });
    }

    public static function anticipationNote(?Carbon $at = null): string
    {
        $at = $at ?? now();

        return 'Saldo Antecipado em '.$at->timezone((string) config('app.timezone', 'America/Sao_Paulo'))->format('d/m/Y, H:i');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function canAnticipate(WalletTransaction $tx, array $meta, ?string $orderStatus): bool
    {
        if ($tx->type !== WalletTransaction::TYPE_CREDIT_SALE_PENDING) {
            return false;
        }
        if (! empty($meta['released_at']) || ! empty($meta['anticipated_at'])) {
            return false;
        }
        if ((float) $tx->amount_net <= 0) {
            return false;
        }
        if ((int) ($tx->order_id ?? 0) < 1 || $orderStatus === null || $orderStatus === '') {
            return false;
        }

        return ! in_array($orderStatus, self::BLOCKED_ORDER_STATUSES, true);
    }

    private function assertCanAnticipate(WalletTransaction $tx): void
    {
        if ($tx->type !== WalletTransaction::TYPE_CREDIT_SALE_PENDING) {
            throw new InvalidArgumentException('Só é possível antecipar vendas em liquidação.');
        }

        $meta = is_array($tx->meta) ? $tx->meta : [];
        if (! empty($meta['released_at']) || ! empty($meta['anticipated_at'])) {
            throw new InvalidArgumentException('Este saldo já foi liberado.');
        }

        if ((float) $tx->amount_net <= 0) {
            throw new InvalidArgumentException('Valor inválido para antecipação.');
        }

        $orderId = (int) ($tx->order_id ?? 0);
        if ($orderId < 1) {
            throw new InvalidArgumentException('Esta movimentação não está vinculada a um pedido.');
        }

        $order = Order::query()->find($orderId);
        if ($order === null) {
            throw new InvalidArgumentException('Pedido da venda não encontrado.');
        }

        if (in_array((string) $order->status, self::BLOCKED_ORDER_STATUSES, true)) {
            throw new InvalidArgumentException('Não é possível antecipar esta venda no status atual do pedido.');
        }
    }
}
