<?php

namespace App\Console\Commands;

use App\Models\CademiIntegration;
use App\Models\Order;
use App\Models\Product;
use App\Services\AccessEmailService;
use App\Services\MemberAreaResolver;
use App\Support\SafeUrl;
use Illuminate\Console\Command;

class DiagnoseProductCheckoutCommand extends Command
{
    protected $signature = 'checkout:diagnose-product
                            {slug : checkout_slug do produto (ex.: 5owdzbx)}
                            {--order= : ID de pedido recente para simular redirect pós-compra}';

    protected $description = 'Diagnóstico de configuração de checkout (redirect, upsell, Cademí, área de membros).';

    public function handle(AccessEmailService $accessEmailService, MemberAreaResolver $memberAreaResolver): int
    {
        $slug = strtolower(trim((string) $this->argument('slug')));
        $product = Product::query()
            ->whereRaw('LOWER(checkout_slug) = ?', [$slug])
            ->first();

        if (! $product) {
            $this->error("Produto não encontrado para slug: {$slug}");

            return self::FAILURE;
        }

        $config = is_array($product->checkout_config) ? $product->checkout_config : [];
        $this->info("Produto #{$product->id} — {$product->name}");
        $this->line("Slug: {$product->checkout_slug} | Tipo: {$product->type} | Tenant: {$product->tenant_id}");
        $this->newLine();

        $rawRedirect = $config['redirect_after_purchase'] ?? '';
        $normalizedRedirect = is_string($rawRedirect) && trim($rawRedirect) !== ''
            ? SafeUrl::normalizeCheckoutRedirect($rawRedirect)
            : null;
        $this->line('Redirect pós-compra:');
        $this->line('  raw: '.($rawRedirect !== '' ? $rawRedirect : '(vazio)'));
        $this->line('  normalizado: '.($normalizedRedirect ?? '(inválido — cai em /checkout/obrigado)'));
        if (is_string($rawRedirect) && trim($rawRedirect) !== '' && $normalizedRedirect === null) {
            $this->warn('  ⚠ URL inválida — provável causa de Server Error no fim do checkout.');
        }

        $upsell = $config['upsell'] ?? [];
        $this->newLine();
        $this->line('Upsell: '.(! empty($upsell['enabled']) ? 'ativo' : 'inativo'));
        if (! empty($upsell['enabled']) && is_array($upsell['products'] ?? null)) {
            foreach ($upsell['products'] as $i => $item) {
                $pid = trim((string) ($item['product_id'] ?? ''));
                $oid = (int) ($item['product_offer_id'] ?? 0);
                $p = $pid !== '' ? Product::find($pid) : null;
                $status = $p ? 'ok' : 'PRODUTO NÃO ENCONTRADO';
                $this->line("  [{$i}] product_id={$pid} offer_id={$oid} → {$status}");
            }
        }

        $downsell = $config['downsell'] ?? [];
        $this->newLine();
        $this->line('Downsell: '.(! empty($downsell['enabled']) ? 'ativo' : 'inativo'));
        if (! empty($downsell['enabled'])) {
            $dpid = trim((string) ($downsell['product_id'] ?? ''));
            $dp = $dpid !== '' ? Product::find($dpid) : null;
            $this->line('  product_id='.$dpid.' → '.($dp ? 'ok' : 'PRODUTO NÃO ENCONTRADO'));
        }

        $gateways = $config['payment_gateways'] ?? [];
        $this->newLine();
        $this->line('Gateways checkout: '.(is_array($gateways) ? implode(', ', array_keys(array_filter($gateways))) : '(padrão tenant)'));

        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            $base = $memberAreaResolver->baseUrlForProduct($product);
            $this->newLine();
            $this->line("Área de membros base URL: {$base}");
            $domain = $product->memberAreaDomain;
            if ($domain) {
                $this->line("  domínio: type={$domain->type} value={$domain->value}");
            }
        }

        if ($product->type === Product::TYPE_LINK) {
            $link = $config['deliverable_link'] ?? '';
            $this->newLine();
            $this->line('Link de entrega: '.($link !== '' ? $link : '(vazio)'));
            $norm = is_string($link) && trim($link) !== '' ? SafeUrl::normalizeCheckoutRedirect($link) : null;
            if ($link !== '' && $norm === null) {
                $this->warn('  ⚠ deliverable_link inválido.');
            }
        }

        $integrations = CademiIntegration::forTenant((int) $product->tenant_id)->where('is_active', true)->get();
        if ($integrations->isNotEmpty()) {
            $this->newLine();
            $this->line('Cademí (integrações ativas): '.$integrations->count());
            $cademiOrder = Order::query()
                ->where('product_id', $product->id)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->first();
            foreach ($integrations as $integration) {
                $mappingNote = 'sem pedido completed para testar';
                if ($cademiOrder) {
                    $mapping = $integration->resolveMappingForOrder($cademiOrder);
                    $mappingNote = $mapping ? 'mapping ok (pedido #'.$cademiOrder->id.')' : 'sem mapping';
                }
                $this->line("  #{$integration->id} {$integration->name}: {$mappingNote}");
            }
        }

        $orderId = $this->option('order');
        if ($orderId !== null) {
            $order = Order::with('user', 'product')->find((int) $orderId);
            if (! $order || (string) $order->product_id !== (string) $product->id) {
                $this->warn("Pedido #{$orderId} não encontrado ou não é deste produto.");
            } else {
                $this->newLine();
                $this->line("Simulação pós-compra (pedido #{$order->id}, status={$order->status}):");
                try {
                    $accessLink = $accessEmailService->getAccessLinkForOrder($order);
                    $this->line('  access_link: '.($accessLink !== '' ? $accessLink : '(vazio)'));
                } catch (\Throwable $e) {
                    $this->error('  access_link FALHOU: '.$e->getMessage());
                }
                $thankYou = route('checkout.thank-you', ['order_id' => $order->id, 'next' => 'login']);
                $this->line("  thank_you: {$thankYou}");
            }
        } else {
            $lastOrder = Order::query()
                ->where('product_id', $product->id)
                ->orderByDesc('id')
                ->first();
            if ($lastOrder) {
                $this->newLine();
                $this->line("Último pedido: #{$lastOrder->id} ({$lastOrder->status}). Use --order={$lastOrder->id} para simular redirect.");
            }
        }

        $this->newLine();
        $this->line('Checkout URL: '.route('checkout.show', ['slug' => $product->checkout_slug]));

        return self::SUCCESS;
    }
}
