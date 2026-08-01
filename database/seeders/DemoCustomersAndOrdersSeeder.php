<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dados fictícios para testar Painel Admin > Clientes / Transações no ambiente local.
 *
 * Uso: php artisan db:seed --class=DemoCustomersAndOrdersSeeder
 *
 * Senha padrão de todos os usuários demo: password
 */
class DemoCustomersAndOrdersSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $sellerA = $this->upsertSeller(
            'seller.demo.a@example.com',
            'Seller Demo Alpha',
            '11987654321',
            '39053344705'
        );
        $sellerB = $this->upsertSeller(
            'seller.demo.b@example.com',
            'Seller Demo Beta',
            '21988776655',
            '11144477735'
        );

        $productA1 = $this->upsertProduct($sellerA, 'demo-mkt', 'Curso Marketing Digital', 197.00);
        $productA2 = $this->upsertProduct($sellerA, 'demo-ebook', 'E-book Funil de Vendas', 47.90);
        $productB1 = $this->upsertProduct($sellerB, 'demo-copy', 'Mentoria de Copywriting', 997.00);

        $customers = [
            $this->upsertCustomer([
                'email' => 'cliente.completo@example.com',
                'name' => 'Ana Clara Souza',
                'phone' => '11999887766',
                'document' => '52998224725',
                'birth_date' => '1992-03-18',
                'address_zip' => '01310100',
                'address_street' => 'Avenida Paulista',
                'address_number' => '1578',
                'address_complement' => 'Sala 12',
                'address_neighborhood' => 'Bela Vista',
                'address_city' => 'São Paulo',
                'address_state' => 'SP',
                'email_verified' => true,
            ]),
            $this->upsertCustomer([
                'email' => 'cliente.sem.cpf@example.com',
                'name' => 'Bruno Lima',
                'phone' => '21991234567',
                'document' => null,
                'birth_date' => null,
                'address_zip' => '20040020',
                'address_street' => 'Rua da Assembleia',
                'address_number' => '10',
                'address_complement' => null,
                'address_neighborhood' => 'Centro',
                'address_city' => 'Rio de Janeiro',
                'address_state' => 'RJ',
                'email_verified' => true,
            ]),
            $this->upsertCustomer([
                'email' => 'cliente.sem.telefone@example.com',
                'name' => 'Carla Mendes',
                'phone' => null,
                'document' => '15350946056',
                'birth_date' => '1988-11-02',
                'address_zip' => null,
                'address_street' => null,
                'address_number' => null,
                'address_complement' => null,
                'address_neighborhood' => null,
                'address_city' => null,
                'address_state' => null,
                'email_verified' => false,
            ]),
            $this->upsertCustomer([
                'email' => 'cliente.sem.endereco@example.com',
                'name' => 'Diego Ferreira',
                'phone' => '31995554433',
                'document' => '10000000019',
                'birth_date' => '1995-07-21',
                'address_zip' => null,
                'address_street' => null,
                'address_number' => null,
                'address_complement' => null,
                'address_neighborhood' => null,
                'address_city' => null,
                'address_state' => null,
                'email_verified' => true,
            ]),
            $this->upsertCustomer([
                'email' => 'cliente.sem.compras@example.com',
                'name' => 'Elena Rocha',
                'phone' => '41997776655',
                'document' => '10000000108',
                'birth_date' => '2000-01-10',
                'address_zip' => '80010000',
                'address_street' => 'Rua XV de Novembro',
                'address_number' => '500',
                'address_complement' => 'Ap 302',
                'address_neighborhood' => 'Centro',
                'address_city' => 'Curitiba',
                'address_state' => 'PR',
                'email_verified' => true,
            ]),
            $this->upsertCustomer([
                'email' => 'cliente.pendente@example.com',
                'name' => 'Fábio Nunes',
                'phone' => '51996665544',
                'document' => '10000000280',
                'birth_date' => '1991-09-09',
                'address_zip' => '90010150',
                'address_street' => 'Rua dos Andradas',
                'address_number' => '1001',
                'address_complement' => null,
                'address_neighborhood' => 'Centro Histórico',
                'address_city' => 'Porto Alegre',
                'address_state' => 'RS',
                'email_verified' => true,
            ]),
        ];

        // Remove pedidos demo anteriores destes clientes (idempotente).
        $customerIds = collect($customers)->pluck('id')->all();
        $demoOrderIds = Order::query()
            ->whereIn('user_id', $customerIds)
            ->where('gateway', 'demo_seed')
            ->pluck('id');
        if ($demoOrderIds->isNotEmpty()) {
            if (Schema::hasTable('order_items')) {
                OrderItem::query()->whereIn('order_id', $demoOrderIds)->delete();
            }
            Order::query()->whereIn('id', $demoOrderIds)->delete();
        }

        $ana = $customers[0];
        $bruno = $customers[1];
        $carla = $customers[2];
        $diego = $customers[3];
        // $elena = sem compras
        $fabio = $customers[5];

        // Ana: várias compras (completed, pending, cancelled, refunded, disputed) + bump
        $this->createOrder($ana, $sellerA, $productA1, [
            'status' => 'completed',
            'amount' => 197.00,
            'payment_method' => 'pix',
            'days_ago' => 40,
            'coupon_code' => null,
            'with_bump' => $productA2,
            'bump_amount' => 47.90,
        ]);
        $this->createOrder($ana, $sellerA, $productA2, [
            'status' => 'completed',
            'amount' => 47.90,
            'payment_method' => 'card',
            'days_ago' => 25,
        ]);
        $this->createOrder($ana, $sellerB, $productB1, [
            'status' => 'pending',
            'amount' => 997.00,
            'payment_method' => 'boleto',
            'days_ago' => 2,
            'metadata' => [
                'checkout_payment_method' => 'boleto',
                'demo_charge_hint' => 'Pedido pendente de demonstração',
            ],
        ]);
        $this->createOrder($ana, $sellerA, $productA1, [
            'status' => 'cancelled',
            'amount' => 197.00,
            'payment_method' => 'pix',
            'days_ago' => 15,
        ]);
        $this->createOrder($ana, $sellerB, $productB1, [
            'status' => 'refunded',
            'amount' => 997.00,
            'payment_method' => 'card',
            'days_ago' => 10,
        ]);
        $this->createOrder($ana, $sellerA, $productA1, [
            'status' => 'disputed',
            'amount' => 197.00,
            'payment_method' => 'card',
            'days_ago' => 5,
        ]);

        // Bruno: 2 completed
        $this->createOrder($bruno, $sellerA, $productA1, [
            'status' => 'completed',
            'amount' => 197.00,
            'payment_method' => 'pix',
            'days_ago' => 20,
            'coupon_code' => 'DEMO10',
        ]);
        $this->createOrder($bruno, $sellerB, $productB1, [
            'status' => 'completed',
            'amount' => 997.00,
            'payment_method' => 'pix',
            'days_ago' => 3,
        ]);

        // Carla: 1 completed (sem telefone no user)
        $this->createOrder($carla, $sellerA, $productA2, [
            'status' => 'completed',
            'amount' => 47.90,
            'payment_method' => 'pix',
            'days_ago' => 8,
            'phone_override' => '11970001122',
        ]);

        // Diego: 1 completed + 1 pending
        $this->createOrder($diego, $sellerB, $productB1, [
            'status' => 'completed',
            'amount' => 997.00,
            'payment_method' => 'card',
            'days_ago' => 12,
        ]);
        $this->createOrder($diego, $sellerA, $productA1, [
            'status' => 'pending',
            'amount' => 197.00,
            'payment_method' => 'pix',
            'days_ago' => 1,
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'demo_charge_hint' => 'PIX pendente de demonstração',
            ],
        ]);

        // Fábio: só pendentes (não aparece na listagem atual de Clientes — útil para contraste)
        $this->createOrder($fabio, $sellerA, $productA1, [
            'status' => 'pending',
            'amount' => 197.00,
            'payment_method' => 'boleto',
            'days_ago' => 0,
        ]);
        $this->createOrder($fabio, $sellerA, $productA2, [
            'status' => 'pending',
            'amount' => 47.90,
            'payment_method' => 'pix',
            'days_ago' => 0,
        ]);

        $this->command?->info('Demo seed OK.');
        $this->command?->table(
            ['Tipo', 'E-mail', 'Senha'],
            [
                ['Admin (já existente)', 'admin@admin.com', '12345678'],
                ['Seller Alpha', 'seller.demo.a@example.com', self::PASSWORD],
                ['Seller Beta', 'seller.demo.b@example.com', self::PASSWORD],
                ['Cliente completo', 'cliente.completo@example.com', self::PASSWORD],
                ['Cliente sem CPF', 'cliente.sem.cpf@example.com', self::PASSWORD],
                ['Cliente sem telefone', 'cliente.sem.telefone@example.com', self::PASSWORD],
                ['Cliente sem endereço', 'cliente.sem.endereco@example.com', self::PASSWORD],
                ['Cliente sem compras', 'cliente.sem.compras@example.com', self::PASSWORD],
                ['Cliente só pendente', 'cliente.pendente@example.com', self::PASSWORD],
            ]
        );
        $this->command?->info('Abra /plataforma/clientes — devem aparecer quem tem compra completed.');
    }

    private function upsertSeller(string $email, string $name, string $phone, string $document): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_INFOPRODUTOR,
                'account_status' => 'approved',
                'person_type' => 'pf',
                'phone' => $phone,
                'document' => $document,
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill(['tenant_id' => $user->id])->save();

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertCustomer(array $data): User
    {
        $attrs = [
            'name' => $data['name'],
            'password' => Hash::make(self::PASSWORD),
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
            'account_status' => 'approved',
            'person_type' => 'pf',
            'phone' => $data['phone'],
            'document' => $data['document'],
            'birth_date' => $data['birth_date'],
            'address_zip' => $data['address_zip'],
            'address_street' => $data['address_street'],
            'address_number' => $data['address_number'],
            'address_complement' => $data['address_complement'],
            'address_neighborhood' => $data['address_neighborhood'],
            'address_city' => $data['address_city'],
            'address_state' => $data['address_state'],
            'email_verified_at' => ! empty($data['email_verified']) ? now() : null,
        ];

        return User::query()->updateOrCreate(['email' => $data['email']], $attrs)->fresh();
    }

    private function upsertProduct(User $seller, string $slug, string $name, float $price): Product
    {
        $existing = Product::query()
            ->where('tenant_id', $seller->id)
            ->where('slug', $slug)
            ->first();

        if ($existing) {
            $existing->forceFill([
                'name' => $name,
                'price' => $price,
                'is_active' => true,
                'type' => Product::TYPE_LINK,
                'billing_type' => Product::BILLING_ONE_TIME,
                'currency' => 'BRL',
            ])->save();

            return $existing->fresh();
        }

        $product = new Product;
        $product->forceFill([
            'tenant_id' => $seller->id,
            'name' => $name,
            'slug' => $slug,
            'checkout_slug' => Str::limit($slug, 16, ''),
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => $price,
            'currency' => 'BRL',
            'is_active' => true,
        ]);
        $product->save();

        return $product->fresh();
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function createOrder(User $customer, User $seller, Product $product, array $opts): Order
    {
        $createdAt = Carbon::now()->subDays((int) ($opts['days_ago'] ?? 0))->subMinutes(random_int(0, 120));
        $phone = $opts['phone_override'] ?? $customer->phone;
        $amount = (float) $opts['amount'];
        $bump = $opts['with_bump'] ?? null;
        $bumpAmount = (float) ($opts['bump_amount'] ?? 0);
        $total = $bump instanceof Product ? round($amount + $bumpAmount, 2) : $amount;

        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => $opts['status'],
            'amount' => $total,
            'email' => $customer->email,
            'cpf' => $customer->document,
            'phone' => $phone,
            'coupon_code' => $opts['coupon_code'] ?? null,
            'gateway' => 'demo_seed',
            'gateway_id' => 'demo_'.Str::lower(Str::random(12)),
            'payment_method' => $opts['payment_method'] ?? 'pix',
            'approved_manually' => false,
            'metadata' => $opts['metadata'] ?? ['seed' => 'demo_customers'],
        ]);

        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        if (Schema::hasTable('order_items')) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => $amount,
                'position' => 0,
            ]);
            if ($bump instanceof Product) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $bump->id,
                    'amount' => $bumpAmount,
                    'position' => 1,
                ]);
            }
        }

        return $order->fresh();
    }
}
