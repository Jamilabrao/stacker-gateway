<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MemberAccessGrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillMemberAccessSubscriptionsCommand extends Command
{
    protected $signature = 'members:backfill-subscriptions
                            {--dry-run : Simula sem gravar alterações}
                            {--limit=0 : Máximo de matrículas a processar (0 = todas)}
                            {--product= : Filtrar por UUID do produto}
                            {--include-inactive : Incluir matrículas que já têm assinatura inativa/expirada}';

    protected $description = 'Opcional: corrige em lote matrículas legadas. No deploy normal, o reparo ocorre automaticamente no primeiro acesso à área.';

    public function handle(MemberAccessGrantService $memberAccessGrant): int
    {
        if (! Schema::hasTable('product_user') || ! Schema::hasTable('subscriptions')) {
            $this->error('Tabelas product_user ou subscriptions não existem. Rode as migrations.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $productFilter = $this->option('product');
        $includeInactive = (bool) $this->option('include-inactive');

        if ($dryRun) {
            $this->warn('Modo dry-run: nenhuma alteração será gravada.');
        }

        $processed = 0;
        $created = 0;
        $skippedHasAccess = 0;
        $skippedNoPlan = 0;
        $skippedHasInactiveOnly = 0;
        $skippedMissing = 0;

        $query = DB::table('product_user')
            ->join('products', 'products.id', '=', 'product_user.product_id')
            ->where('products.billing_type', Product::BILLING_SUBSCRIPTION)
            ->select('product_user.user_id', 'product_user.product_id')
            ->orderBy('product_user.user_id')
            ->orderBy('product_user.product_id');

        if (is_string($productFilter) && trim($productFilter) !== '') {
            $query->where('product_user.product_id', trim($productFilter));
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            $processed++;

            $user = User::query()->find($row->user_id);
            $product = Product::query()->find($row->product_id);

            if (! $user || ! $product) {
                $skippedMissing++;

                continue;
            }

            if ($memberAccessGrant->hasValidActiveSubscription($user, $product)) {
                $skippedHasAccess++;

                continue;
            }

            if (! $includeInactive) {
                $hasAnySubscription = Subscription::query()
                    ->where('user_id', $user->id)
                    ->where('product_id', $product->id)
                    ->exists();

                if ($hasAnySubscription) {
                    $skippedHasInactiveOnly++;

                    continue;
                }
            }

            $plan = $memberAccessGrant->resolvePlan($product);
            if (! $plan) {
                $skippedNoPlan++;
                $this->line("  [sem plano] user={$user->id} product={$product->id} ({$product->name})");

                continue;
            }

            if ($dryRun) {
                $created++;
                $this->line("  [dry-run] criaria assinatura ({$plan->name}) user={$user->email} product={$product->name}");

                continue;
            }

            $before = Subscription::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->count();

            $memberAccessGrant->grant($user, $product);

            $after = Subscription::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->count();

            if ($after > $before) {
                $created++;
                $this->line("  [ok] assinatura criada user={$user->email} product={$product->name} plano={$plan->name}");
            }
        }

        $this->newLine();
        $this->info("Processados: {$processed}");
        $this->info('Assinaturas '.($dryRun ? 'a criar' : 'criadas').": {$created}");
        $this->info("Ignorados (já com acesso válido): {$skippedHasAccess}");
        $this->info("Ignorados (assinatura inativa — use --include-inactive): {$skippedHasInactiveOnly}");
        $this->info("Ignorados (produto sem planos): {$skippedNoPlan}");
        $this->info("Ignorados (usuário/produto ausente): {$skippedMissing}");

        return self::SUCCESS;
    }
}
