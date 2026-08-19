<?php

namespace Database\Seeders;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Support\WalletCreditReference;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dados fictícios para visualizar o painel local (plataforma + vendedor).
 *
 * Uso: php artisan db:seed --class=LocalPreviewSeeder --force
 *
 * Senha padrão: password
 * Admin: admin@admin.com / 12345678
 */
class LocalPreviewSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const EMAIL_DOMAIN = 'preview.stacker.local';

    /** @var list<array{slug: string, method: string}> */
    private const ACQUIRERS = [
        ['slug' => 'cajupay', 'method' => 'pix'],
        ['slug' => 'efi', 'method' => 'pix'],
        ['slug' => 'woovi', 'method' => 'pix'],
        ['slug' => 'bspay', 'method' => 'pix'],
        ['slug' => 'mercadopago', 'method' => 'pix'],
        ['slug' => 'pagarme', 'method' => 'card'],
        ['slug' => 'stripe', 'method' => 'card'],
        ['slug' => 'efi', 'method' => 'boleto'],
        ['slug' => 'cajupay', 'method' => 'card'],
        ['slug' => 'linaopenx', 'method' => 'open_finance'],
    ];

    public function run(): void
    {
        $this->purgePreviousPreviewData();

        $admin = $this->upsertAdmin();

        $marina = $this->upsertSeller([
            'email' => 'marina.costa@'.self::EMAIL_DOMAIN,
            'name' => 'Marina Costa',
            'phone' => '11991001122',
            'document' => '52998224725',
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
            'company_name' => 'Marina Costa Educação LTDA',
        ]);
        $rafael = $this->upsertSeller([
            'email' => 'rafael.alves@'.self::EMAIL_DOMAIN,
            'name' => 'Rafael Alves',
            'phone' => '21988003344',
            'document' => '39053344705',
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
            'company_name' => 'Alves Mentoria',
        ]);
        $juliana = $this->upsertSeller([
            'email' => 'juliana.mendes@'.self::EMAIL_DOMAIN,
            'name' => 'Juliana Mendes',
            'phone' => '31997005566',
            'document' => '11144477735',
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'approved',
            'company_name' => 'JM Infoprodutos',
        ]);
        $thiago = $this->upsertSeller([
            'email' => 'thiago.rocha@'.self::EMAIL_DOMAIN,
            'name' => 'Thiago Rocha',
            'phone' => '41996007788',
            'document' => '15350946056',
            'kyc_status' => User::KYC_REJECTED,
            'account_status' => 'approved',
            'company_name' => 'Rocha Digital',
            'kyc_rejection_reason' => 'Documento ilegível (dado fictício de preview).',
        ]);

        $curso = $this->upsertProduct($marina, [
            'name' => 'Curso Completo de Tráfego Pago',
            'slug' => 'preview-trafego',
            'price' => 297.00,
            'type' => Product::TYPE_LINK,
        ]);
        $mentoria = $this->upsertProduct($marina, [
            'name' => 'Mentoria Escala 7 dígitos',
            'slug' => 'preview-mentoria',
            'price' => 1497.00,
            'type' => Product::TYPE_LINK_PAGAMENTO,
        ]);
        $ebook = $this->upsertProduct($rafael, [
            'name' => 'E-book Copy que Vende',
            'slug' => 'preview-ebook',
            'price' => 47.90,
            'type' => Product::TYPE_LINK,
        ]);
        $comunidade = $this->upsertProduct($rafael, [
            'name' => 'Comunidade VIP de Copy',
            'slug' => 'preview-comunidade',
            'price' => 97.00,
            'type' => Product::TYPE_AREA_MEMBROS_EXTERNA,
        ]);
        $this->upsertProduct($juliana, [
            'name' => 'Workshop Instagram (aguardando aprovação)',
            'slug' => 'preview-workshop',
            'price' => 67.00,
            'type' => Product::TYPE_LINK,
            'approval_status' => Product::APPROVAL_PENDING,
        ]);

        $customers = [];
        $customerSpecs = [
            ['Ana Souza', 'ana.souza'],
            ['Bruno Lima', 'bruno.lima'],
            ['Carla Mendes', 'carla.mendes'],
            ['Diego Ferreira', 'diego.ferreira'],
            ['Elena Rocha', 'elena.rocha'],
            ['Fábio Nunes', 'fabio.nunes'],
            ['Gabriela Dias', 'gabriela.dias'],
            ['Henrique Pinto', 'henrique.pinto'],
        ];
        foreach ($customerSpecs as $i => [$name, $local]) {
            $customers[] = $this->upsertCustomer([
                'email' => $local.'@'.self::EMAIL_DOMAIN,
                'name' => $name,
                'phone' => '1199'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
                'document' => $this->fakeCpf($i),
            ]);
        }

        $catalog = [
            [$marina, $curso, 297.00],
            [$marina, $mentoria, 1497.00],
            [$rafael, $ebook, 47.90],
            [$rafael, $comunidade, 97.00],
        ];

        $statuses = ['completed', 'completed', 'completed', 'completed', 'pending', 'cancelled', 'refunded', 'completed', 'disputed', 'completed'];
        $orders = [];
        for ($i = 0; $i < 48; $i++) {
            [$seller, $product, $price] = $catalog[$i % count($catalog)];
            $customer = $customers[$i % count($customers)];
            $acq = self::ACQUIRERS[$i % count(self::ACQUIRERS)];
            $status = $statuses[$i % count($statuses)];
            $orders[] = $this->createOrder($customer, $seller, $product, [
                'status' => $status,
                'amount' => $price,
                'gateway' => $acq['slug'],
                'payment_method' => $acq['method'],
                'days_ago' => $i % 40,
            ]);
        }

        $completed = collect($orders)->first(fn (Order $o) => $o->status === 'completed' && $o->tenant_id === $marina->id);
        if ($completed && Schema::hasTable('refund_requests')) {
            RefundRequest::query()->updateOrCreate(
                ['order_id' => $completed->id],
                [
                    'user_id' => $completed->user_id,
                    'tenant_id' => $completed->tenant_id,
                    'status' => RefundRequest::STATUS_PENDING,
                    'customer_reason' => 'Quero reembolso: o conteúdo não era o que eu esperava (preview).',
                ]
            );
        }

        $disputed = collect($orders)->first(fn (Order $o) => $o->status === 'disputed');
        if ($disputed && Schema::hasTable('med_disputes')) {
            MedDispute::query()->updateOrCreate(
                ['order_id' => $disputed->id],
                [
                    'tenant_id' => $disputed->tenant_id,
                    'responsible_party' => MedDispute::PARTY_TENANT,
                    'cajupay_dispute_id' => 'preview-med-'.$disputed->id,
                    'cajupay_payment_id' => (string) ($disputed->gateway_id ?? 'preview-pay'),
                    'status' => MedDispute::STATUS_OPEN,
                    'amount_cents' => (int) round(((float) $disputed->amount) * 100),
                    'currency' => 'BRL',
                    'txid' => 'PREVIEWTXID'.$disputed->id,
                    'opened_at' => now()->subDays(2),
                    'metadata' => ['seed' => 'local_preview'],
                ]
            );
        }

        foreach ([$marina, $rafael] as $seller) {
            $this->syncWallet($seller, collect($orders)->where('tenant_id', $seller->id));
        }

        if (Schema::hasTable('withdrawals')) {
            $this->seedWithdrawals($marina);
            $this->seedWithdrawals($rafael, true);
        }

        $this->call(DemoMemberAreaProductSeeder::class);

        $this->command?->info('Preview local OK. Banco não foi recriado — só dados fictícios inseridos/atualizados.');
        $this->command?->table(
            ['Acesso', 'E-mail', 'Senha'],
            [
                ['Admin plataforma', $admin->email, '12345678'],
                ['Infoprodutor (KYC ok)', $marina->email, self::PASSWORD],
                ['Infoprodutor (KYC ok)', $rafael->email, self::PASSWORD],
                ['Infoprodutor (KYC pendente)', $juliana->email, self::PASSWORD],
                ['Infoprodutor (KYC recusado)', $thiago->email, self::PASSWORD],
                ['Cliente', 'ana.souza@'.self::EMAIL_DOMAIN, self::PASSWORD],
            ]
        );
        $this->command?->info('Plataforma: /plataforma/login  |  Vendedor: /login');
    }

    private function purgePreviousPreviewData(): void
    {
        $sellerIds = User::query()
            ->where('email', 'like', '%@'.self::EMAIL_DOMAIN)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->pluck('id');

        $customerIds = User::query()
            ->where('email', 'like', '%@'.self::EMAIL_DOMAIN)
            ->whereIn('role', User::buyerRoleValues())
            ->pluck('id');

        $orderQuery = Order::query()->where(function ($q) use ($sellerIds, $customerIds) {
            if ($sellerIds->isNotEmpty()) {
                $q->orWhereIn('tenant_id', $sellerIds);
            }
            if ($customerIds->isNotEmpty()) {
                $q->orWhereIn('user_id', $customerIds);
            }
            $q->orWhere('metadata->seed', 'local_preview');
        });
        $orderIds = $orderQuery->pluck('id');

        if ($orderIds->isNotEmpty()) {
            if (Schema::hasTable('refund_requests')) {
                RefundRequest::query()->whereIn('order_id', $orderIds)->delete();
            }
            if (Schema::hasTable('med_disputes')) {
                MedDispute::query()->whereIn('order_id', $orderIds)->delete();
            }
            if (Schema::hasTable('wallet_transactions')) {
                WalletTransaction::query()->whereIn('order_id', $orderIds)->delete();
            }
            if (Schema::hasTable('order_items')) {
                OrderItem::query()->whereIn('order_id', $orderIds)->delete();
            }
            Order::query()->whereIn('id', $orderIds)->delete();
        }

        if ($sellerIds->isNotEmpty() && Schema::hasTable('withdrawals')) {
            $wIds = Withdrawal::query()->whereIn('tenant_id', $sellerIds)->pluck('id');
            if ($wIds->isNotEmpty() && Schema::hasTable('wallet_transactions')) {
                WalletTransaction::query()->whereIn('withdrawal_id', $wIds)->delete();
            }
            Withdrawal::query()->whereIn('tenant_id', $sellerIds)->delete();
        }

        if ($sellerIds->isNotEmpty() && Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->whereIn('tenant_id', $sellerIds)->delete();
        }

        if ($sellerIds->isNotEmpty()) {
            Product::query()->whereIn('tenant_id', $sellerIds)->forceDelete();
        }

        User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->delete();
    }

    private function upsertAdmin(): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin Preview',
                'password' => Hash::make('12345678'),
                'role' => User::ROLE_PLATFORM_ADMIN,
                'tenant_id' => null,
                'account_status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertSeller(array $data): User
    {
        $attrs = [
            'name' => $data['name'],
            'password' => Hash::make(self::PASSWORD),
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => $data['account_status'] ?? 'approved',
            'person_type' => 'pf',
            'phone' => $data['phone'],
            'document' => $data['document'],
            'company_name' => $data['company_name'] ?? null,
            'email_verified_at' => now(),
        ];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = $data['kyc_status'] ?? User::KYC_APPROVED;
        }
        if (Schema::hasColumn('users', 'kyc_rejection_reason')) {
            $attrs['kyc_rejection_reason'] = $data['kyc_rejection_reason'] ?? null;
        }
        if (Schema::hasColumn('users', 'seller_onboarded_at')) {
            $attrs['seller_onboarded_at'] = now()->subDays(20);
        }
        if (Schema::hasColumn('users', 'privacy_policy_accepted_at')) {
            $attrs['privacy_policy_accepted_at'] = now()->subDays(20);
        }
        if (Schema::hasColumn('users', 'terms_accepted_at')) {
            $attrs['terms_accepted_at'] = now()->subDays(20);
        }

        $user = User::query()->updateOrCreate(['email' => $data['email']], $attrs);
        $user->forceFill(['tenant_id' => $user->id])->save();

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertCustomer(array $data): User
    {
        return User::query()->updateOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_CLIENTE,
                'tenant_id' => null,
                'account_status' => 'approved',
                'person_type' => 'pf',
                'phone' => $data['phone'],
                'document' => $data['document'],
                'email_verified_at' => now(),
            ]
        )->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertProduct(User $seller, array $data): Product
    {
        $existing = Product::withTrashed()
            ->where('tenant_id', $seller->id)
            ->where('slug', $data['slug'])
            ->first();

        $payload = [
            'tenant_id' => $seller->id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'type' => $data['type'] ?? Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => $data['price'],
            'currency' => 'BRL',
            'is_active' => true,
            'description' => 'Produto fictício para visualização local.',
        ];
        if (Schema::hasColumn('products', 'approval_status')) {
            $payload['approval_status'] = $data['approval_status'] ?? Product::APPROVAL_APPROVED;
            $payload['approval_source'] = Product::APPROVAL_SOURCE_MANUAL;
        }

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->forceFill($payload)->save();

            return $existing->fresh();
        }

        $product = new Product;
        $product->forceFill($payload);
        $product->save();

        return $product->fresh();
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function createOrder(User $customer, User $seller, Product $product, array $opts): Order
    {
        $createdAt = Carbon::now()
            ->subDays((int) ($opts['days_ago'] ?? 0))
            ->subMinutes(random_int(5, 400));
        $method = (string) ($opts['payment_method'] ?? 'pix');
        $amount = (float) $opts['amount'];

        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => $opts['status'],
            'amount' => $amount,
            'email' => $customer->email,
            'cpf' => $customer->document,
            'phone' => $customer->phone,
            'gateway' => $opts['gateway'],
            'gateway_id' => 'preview_'.Str::lower(Str::random(14)),
            'payment_method' => $method,
            'approved_manually' => false,
            'metadata' => [
                'seed' => 'local_preview',
                'checkout_payment_method' => $method,
            ],
        ]);

        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        if (Schema::hasTable('order_items')) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => $amount,
                'position' => 0,
            ]);
        }

        return $order->fresh();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    private function syncWallet(User $seller, $orders): void
    {
        if (! Schema::hasTable('tenant_wallets')) {
            return;
        }

        $available = 0.0;
        $pending = 0.0;
        $pix = 0.0;
        $card = 0.0;
        $boleto = 0.0;

        foreach ($orders as $order) {
            if ($order->status !== 'completed') {
                continue;
            }
            $gross = (float) $order->amount;
            $fee = round($gross * 0.0499, 2);
            $net = round($gross - $fee, 2);
            $bucket = match ($order->payment_method) {
                'card', 'apple_pay', 'google_pay' => 'card',
                'boleto' => 'boleto',
                default => 'pix',
            };
            $available += $net;
            if ($bucket === 'pix') {
                $pix += $net;
            } elseif ($bucket === 'card') {
                $card += $net;
            } else {
                $boleto += $net;
            }

            if (Schema::hasTable('wallet_transactions')) {
                WalletTransaction::query()->updateOrCreate(
                    ['credit_reference' => WalletCreditReference::forDirectSale((int) $order->id, (int) $seller->id)],
                    [
                        'tenant_id' => $seller->id,
                        'order_id' => $order->id,
                        'bucket' => $bucket,
                        'type' => WalletTransaction::TYPE_CREDIT_SALE,
                        'amount_gross' => $gross,
                        'amount_fee' => $fee,
                        'amount_net' => $net,
                        'meta' => ['seed' => 'local_preview'],
                    ]
                );
            }
        }

        $pending = round($available * 0.12, 2);
        $available = round($available - $pending, 2);

        $wallet = [
            'available_balance' => $available,
            'pending_balance' => $pending,
            'currency' => 'BRL',
        ];
        foreach ([
            'available_pix' => round($pix * 0.88, 2),
            'available_card' => round($card * 0.88, 2),
            'available_boleto' => round($boleto * 0.88, 2),
            'pending_pix' => round($pix * 0.12, 2),
            'pending_card' => round($card * 0.12, 2),
            'pending_boleto' => round($boleto * 0.12, 2),
        ] as $col => $val) {
            if (Schema::hasColumn('tenant_wallets', $col)) {
                $wallet[$col] = $val;
            }
        }

        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            $wallet
        );
    }

    private function seedWithdrawals(User $seller, bool $light = false): void
    {
        $rows = $light
            ? [
                ['amount' => 350.00, 'status' => 'paid', 'provider' => 'cajupay', 'days' => 4],
            ]
            : [
                ['amount' => 1250.00, 'status' => 'paid', 'provider' => 'cajupay', 'days' => 8],
                ['amount' => 480.00, 'status' => 'pending', 'provider' => 'woovi', 'days' => 1],
                ['amount' => 220.00, 'status' => 'failed', 'provider' => 'bspay', 'days' => 3],
            ];

        foreach ($rows as $row) {
            $fee = 2.00;
            $created = now()->subDays($row['days']);
            $w = Withdrawal::query()->create([
                'tenant_id' => $seller->id,
                'user_id' => $seller->id,
                'amount' => $row['amount'],
                'fee_amount' => $fee,
                'net_amount' => round($row['amount'] - $fee, 2),
                'bucket' => 'pix',
                'status' => $row['status'] === 'failed' ? 'failed' : ($row['status'] === 'paid' ? 'paid' : 'pending'),
                'failed_reason' => $row['status'] === 'failed' ? 'Falha simulada no preview local.' : null,
                'notes' => 'Saque fictício de preview',
                'currency' => 'BRL',
                'payout_provider' => $row['provider'],
                'payout_external_id' => 'preview-wd-'.Str::lower(Str::random(10)),
                'payout_manual' => false,
            ]);
            $w->forceFill(['created_at' => $created, 'updated_at' => $created])->save();
        }
    }

    private function fakeCpf(int $i): string
    {
        $bases = [
            '52998224725',
            '39053344705',
            '11144477735',
            '15350946056',
            '10000000019',
            '10000000108',
            '10000000280',
            '10000000361',
        ];

        return $bases[$i % count($bases)];
    }
}
