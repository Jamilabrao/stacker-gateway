<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\WalletCreditReference;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Insere vendas em liquidação no banco local para conferir a coluna Liquidação.
 *
 * Uso: php artisan db:seed --class=LocalPendingSettlementSeeder
 */
class LocalPendingSettlementSeeder extends Seeder
{
    private const GATEWAY = 'local_settlement_demo';

    private const FEE_RATE = 0.0499;

    public function run(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->command?->warn('Tabelas de carteira ausentes.');

            return;
        }

        $seller = $this->resolveSeller();
        if ($seller === null) {
            $this->command?->error('Nenhum infoprodutor encontrado no banco local.');

            return;
        }

        $customer = $this->resolveCustomer();
        $product = $this->resolveProduct($seller);

        $this->purgePrevious($seller->id);

        $rows = [
            [
                'amount' => 197.00,
                'method' => 'pix',
                'bucket' => 'pix',
                'days_ago' => 1,
                'clears_in_days' => 2,
                'released' => false,
                'note' => 'PIX D+2 — ainda em liquidação',
            ],
            [
                'amount' => 497.00,
                'method' => 'card',
                'bucket' => 'card',
                'days_ago' => 3,
                'clears_in_days' => 14,
                'released' => false,
                'note' => 'Cartão D+14 — ainda em liquidação',
            ],
            [
                'amount' => 67.00,
                'method' => 'boleto',
                'bucket' => 'boleto',
                'days_ago' => 2,
                'clears_in_days' => 7,
                'released' => false,
                'note' => 'Boleto D+7 — ainda em liquidação',
            ],
            [
                'amount' => 97.00,
                'method' => 'pix',
                'bucket' => 'pix',
                'days_ago' => 5,
                'clears_in_days' => 0,
                'released' => true,
                'note' => 'PIX já liberado na carteira',
            ],
        ];

        $pendingPix = 0.0;
        $pendingCard = 0.0;
        $pendingBoleto = 0.0;
        $availablePix = 0.0;

        foreach ($rows as $row) {
            $order = $this->createCompletedOrder($customer, $seller, $product, $row);
            $fee = round($row['amount'] * self::FEE_RATE, 2);
            $net = round($row['amount'] - $fee, 2);
            $createdAt = Carbon::now()->subDays($row['days_ago'])->subHours(3);
            $clearsAt = $createdAt->copy()->addDays($row['clears_in_days']);

            $meta = [
                'seed' => 'local_pending_settlement',
                'payment_method' => $row['method'],
                'clears_at' => $clearsAt->toIso8601String(),
                'portion' => 'main',
                'note' => $row['note'],
            ];

            if ($row['released']) {
                $pendingTx = WalletTransaction::query()->create([
                    'tenant_id' => $seller->id,
                    'order_id' => $order->id,
                    'bucket' => $row['bucket'],
                    'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
                    'credit_reference' => WalletCreditReference::forPendingSale($order->id, $seller->id, 'main'),
                    'amount_gross' => $row['amount'],
                    'amount_fee' => $fee,
                    'amount_net' => $net,
                    'meta' => $meta,
                ]);
                $pendingTx->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

                $releasedAt = $clearsAt->copy();
                $saleTx = WalletTransaction::query()->create([
                    'tenant_id' => $seller->id,
                    'order_id' => $order->id,
                    'bucket' => $row['bucket'],
                    'type' => WalletTransaction::TYPE_CREDIT_SALE,
                    'credit_reference' => WalletCreditReference::forDirectSale($order->id, $seller->id).':released',
                    'amount_gross' => $row['amount'],
                    'amount_fee' => $fee,
                    'amount_net' => $net,
                    'meta' => array_merge($meta, [
                        'from_pending_wallet_transaction_id' => $pendingTx->id,
                        'released_at' => $releasedAt->toIso8601String(),
                    ]),
                ]);
                $saleTx->forceFill(['created_at' => $releasedAt, 'updated_at' => $releasedAt])->save();

                $pendingTx->meta = array_merge($meta, [
                    'released_at' => $releasedAt->toIso8601String(),
                    'released_to_wallet_transaction_id' => $saleTx->id,
                ]);
                $pendingTx->save();

                $availablePix += $net;
            } else {
                $tx = WalletTransaction::query()->create([
                    'tenant_id' => $seller->id,
                    'order_id' => $order->id,
                    'bucket' => $row['bucket'],
                    'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
                    'credit_reference' => WalletCreditReference::forPendingSale($order->id, $seller->id, 'main'),
                    'amount_gross' => $row['amount'],
                    'amount_fee' => $fee,
                    'amount_net' => $net,
                    'meta' => $meta,
                ]);
                $tx->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

                if ($row['bucket'] === 'pix') {
                    $pendingPix += $net;
                } elseif ($row['bucket'] === 'card') {
                    $pendingCard += $net;
                } else {
                    $pendingBoleto += $net;
                }
            }
        }

        $wallet = TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $seller->id],
            [
                'currency' => 'BRL',
                'available_balance' => 0,
                'pending_balance' => 0,
                'available_pix' => 0,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );

        $wallet->pending_pix = round((float) $wallet->pending_pix + $pendingPix, 2);
        $wallet->pending_card = round((float) $wallet->pending_card + $pendingCard, 2);
        $wallet->pending_boleto = round((float) $wallet->pending_boleto + $pendingBoleto, 2);
        $wallet->available_pix = round((float) $wallet->available_pix + $availablePix, 2);
        $wallet->pending_balance = round(
            (float) $wallet->pending_pix + (float) $wallet->pending_card + (float) $wallet->pending_boleto,
            2
        );
        $wallet->available_balance = round(
            (float) $wallet->available_pix + (float) $wallet->available_card + (float) $wallet->available_boleto,
            2
        );
        $wallet->save();

        $this->command?->info(sprintf(
            'Infoprodutor #%d %s — pendente R$ %s · disponível R$ %s. Abra /plataforma/usuarios/%d?tab=wallet',
            $seller->id,
            $seller->name,
            number_format((float) $wallet->pending_balance, 2, ',', '.'),
            number_format((float) $wallet->available_balance, 2, ',', '.'),
            $seller->id
        ));
    }

    private function resolveSeller(): ?User
    {
        foreach (['seller.alpha@local.test', 'seller.beta@local.test'] as $email) {
            $seller = User::query()
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->where('email', $email)
                ->first();
            if ($seller !== null) {
                return $seller;
            }
        }

        return User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->orderBy('id')
            ->first();
    }

    private function resolveCustomer(): User
    {
        $existing = User::query()->where('email', 'ana.tx@local.test')->first();
        if ($existing !== null) {
            return $existing;
        }

        return User::query()->updateOrCreate(
            ['email' => 'cliente.liquidacao@local.test'],
            [
                'name' => 'Cliente Liquidação',
                'password' => 'password',
                'role' => User::ROLE_CLIENTE,
                'tenant_id' => null,
                'account_status' => 'approved',
                'person_type' => 'pf',
                'document' => '52998224725',
                'email_verified_at' => now(),
            ]
        );
    }

    private function resolveProduct(User $seller): Product
    {
        $existing = Product::query()->where('tenant_id', $seller->id)->orderBy('id')->first();
        if ($existing !== null) {
            return $existing;
        }

        $product = new Product;
        $product->forceFill([
            'tenant_id' => $seller->id,
            'name' => 'Produto Liquidação Local',
            'slug' => 'local-liquidacao-'.Str::lower(Str::random(6)),
            'checkout_slug' => 'locliq'.Str::lower(Str::random(4)),
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 197.00,
            'currency' => 'BRL',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
            'description' => 'Produto fictício para conferir liquidação.',
        ]);
        $product->save();

        return $product->fresh();
    }

    /**
     * @param  array{amount: float, method: string, days_ago: int}  $row
     */
    private function createCompletedOrder(User $customer, User $seller, Product $product, array $row): Order
    {
        $createdAt = Carbon::now()->subDays($row['days_ago'])->subHours(3);
        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => $row['amount'],
            'email' => $customer->email,
            'cpf' => $customer->document,
            'phone' => $customer->phone,
            'gateway' => self::GATEWAY,
            'gateway_id' => 'local_stl_'.Str::lower(Str::random(10)),
            'payment_method' => $row['method'],
            'approved_manually' => false,
            'metadata' => [
                'seed' => 'local_pending_settlement',
                'checkout_payment_method' => $row['method'],
            ],
        ]);
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        if (Schema::hasTable('order_items')) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => $row['amount'],
                'position' => 0,
            ]);
        }

        return $order->fresh();
    }

    private function purgePrevious(int $tenantId): void
    {
        $orderIds = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('gateway', self::GATEWAY)
            ->pluck('id');

        if ($orderIds->isNotEmpty()) {
            $nets = WalletTransaction::query()
                ->whereIn('order_id', $orderIds)
                ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
                ->get();

            $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->first();
            if ($wallet !== null) {
                foreach ($nets as $tx) {
                    $meta = is_array($tx->meta) ? $tx->meta : [];
                    $released = ! empty($meta['released_at']);
                    $net = (float) $tx->amount_net;
                    if ($released) {
                        $wallet->available_pix = round(max(0, (float) $wallet->available_pix - $net), 2);
                    } elseif ($tx->bucket === 'pix') {
                        $wallet->pending_pix = round(max(0, (float) $wallet->pending_pix - $net), 2);
                    } elseif ($tx->bucket === 'card') {
                        $wallet->pending_card = round(max(0, (float) $wallet->pending_card - $net), 2);
                    } else {
                        $wallet->pending_boleto = round(max(0, (float) $wallet->pending_boleto - $net), 2);
                    }
                }
                $wallet->pending_balance = round(
                    (float) $wallet->pending_pix + (float) $wallet->pending_card + (float) $wallet->pending_boleto,
                    2
                );
                $wallet->available_balance = round(
                    (float) $wallet->available_pix + (float) $wallet->available_card + (float) $wallet->available_boleto,
                    2
                );
                $wallet->save();
            }

            WalletTransaction::query()->whereIn('order_id', $orderIds)->delete();
            if (Schema::hasTable('order_items')) {
                OrderItem::query()->whereIn('order_id', $orderIds)->delete();
            }
            Order::query()->whereIn('id', $orderIds)->delete();
        }
    }
}
