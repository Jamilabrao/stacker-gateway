<?php

namespace App\Services;

use App\Models\Product;

class ProductDeliverablePreviewService
{
    public function __construct(
        protected MemberAreaResolver $memberAreaResolver,
    ) {}

    /**
     * @return array{
     *     kind: string,
     *     title: string,
     *     description: string,
     *     primary_url: ?string,
     *     checkout_url: ?string,
     *     can_open: bool,
     *     limitations: ?string
     * }
     */
    public function forAdmin(Product $product): array
    {
        $checkoutUrl = $this->checkoutUrl($product);
        $apiPixNote = 'Vendas apenas via API PIX sem produto no catálogo não aparecem aqui; o entregável não pode ser auditado pela plataforma.';

        return match ($product->type) {
            Product::TYPE_LINK => $this->externalLinkPreview($product, $checkoutUrl, $apiPixNote),
            Product::TYPE_LINK_PAGAMENTO => $this->checkoutOnlyPreview($checkoutUrl, $apiPixNote),
            Product::TYPE_AREA_MEMBROS => $this->memberAreaPreview($product, $checkoutUrl),
            Product::TYPE_AREA_MEMBROS_EXTERNA => $this->externalPlatformPreview($product, $checkoutUrl),
            Product::TYPE_PRODUTO_FISICO => $this->physicalPreview($checkoutUrl),
            Product::TYPE_APLICATIVO => $this->legacyAppPreview($checkoutUrl),
            default => [
                'kind' => 'none',
                'title' => 'Entregável não identificado',
                'description' => 'Tipo de produto sem preview configurado.',
                'primary_url' => null,
                'checkout_url' => $checkoutUrl,
                'can_open' => false,
                'limitations' => $apiPixNote,
            ],
        };
    }

    public function typeLabel(string $type): string
    {
        $config = Product::typeConfig();

        return $config[$type]['label'] ?? $type;
    }

    private function externalLinkPreview(Product $product, ?string $checkoutUrl, string $apiPixNote): array
    {
        $config = is_array($product->checkout_config) ? $product->checkout_config : [];
        $link = trim((string) ($config['deliverable_link'] ?? ''));

        if ($link === '') {
            return [
                'kind' => 'external_link',
                'title' => 'Link de entrega',
                'description' => 'Produto do tipo Link, mas nenhuma URL de entrega foi cadastrada.',
                'primary_url' => null,
                'checkout_url' => $checkoutUrl,
                'can_open' => false,
                'limitations' => 'Configure o link entregável na edição do produto para auditar o conteúdo (Google Drive, Telegram, etc.). '.$apiPixNote,
            ];
        }

        return [
            'kind' => 'external_link',
            'title' => 'Link de entrega',
            'description' => 'URL entregue ao comprador após o pagamento. Verifique se o conteúdo cumpre as regras da plataforma.',
            'primary_url' => $link,
            'checkout_url' => $checkoutUrl,
            'can_open' => filter_var($link, FILTER_VALIDATE_URL) !== false,
            'limitations' => null,
        ];
    }

    private function checkoutOnlyPreview(?string $checkoutUrl, string $apiPixNote): array
    {
        return [
            'kind' => 'checkout_only',
            'title' => 'Somente link de pagamento',
            'description' => 'Este produto só gera checkout/cobrança. Não há entregável automático configurado na plataforma.',
            'primary_url' => $checkoutUrl,
            'checkout_url' => $checkoutUrl,
            'can_open' => $checkoutUrl !== null,
            'limitations' => 'Não é possível inspecionar material entregue (Drive, Telegram, etc.) neste tipo. '.$apiPixNote,
        ];
    }

    private function memberAreaPreview(Product $product, ?string $checkoutUrl): array
    {
        try {
            $url = $this->memberAreaResolver->baseUrlForProduct($product);
        } catch (\Throwable) {
            $url = null;
        }

        $slug = (string) ($product->checkout_slug ?? '');
        $domain = $product->relationLoaded('memberAreaDomain')
            ? $product->memberAreaDomain
            : $product->memberAreaDomain()->first();

        $domainHint = '';
        if ($domain && $domain->value) {
            $domainHint = match ($domain->type) {
                'custom' => 'Domínio customizado: '.$domain->value,
                'subdomain' => 'Subdomínio: '.$domain->value,
                'path' => 'Path: /m/'.$domain->value,
                default => '',
            };
        }

        $description = 'Área de membros hospedada na plataforma. O conteúdo (aulas, arquivos) fica dentro da área — abra o link para avaliar a página pública de acesso.';
        if ($domainHint !== '') {
            $description .= ' '.$domainHint.'.';
        } elseif ($slug !== '') {
            $description .= ' Slug: '.$slug.'.';
        }

        return [
            'kind' => 'member_area',
            'title' => 'Área de membros',
            'description' => $description,
            'primary_url' => $url,
            'checkout_url' => $checkoutUrl,
            'can_open' => is_string($url) && $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false,
            'limitations' => null,
        ];
    }

    private function externalPlatformPreview(Product $product, ?string $checkoutUrl): array
    {
        $config = is_array($product->member_area_config) ? $product->member_area_config : [];
        $cademiId = $config['cademi_integration_id'] ?? null;
        $cademiProductIds = $config['cademi_produto_ids'] ?? $config['cademi_produto_id'] ?? null;

        $details = [];
        if ($cademiId) {
            $details[] = 'Integração Cademí #'.(int) $cademiId;
        }
        if ($cademiProductIds) {
            $ids = is_array($cademiProductIds) ? implode(', ', array_map('strval', $cademiProductIds)) : (string) $cademiProductIds;
            $details[] = 'Produto(s) Cademí: '.$ids;
        }

        $extra = $details !== [] ? ' '.implode('. ', $details).'.' : '';

        return [
            'kind' => 'external_platform',
            'title' => 'Área de membros externa',
            'description' => 'A entrega ocorre em plataforma externa (ex.: Cademí) após o pagamento. Não há URL interna inspecionável.'.$extra,
            'primary_url' => null,
            'checkout_url' => $checkoutUrl,
            'can_open' => false,
            'limitations' => 'Audite o conteúdo diretamente na plataforma externa configurada pelo vendedor.',
        ];
    }

    private function physicalPreview(?string $checkoutUrl): array
    {
        return [
            'kind' => 'physical',
            'title' => 'Produto físico',
            'description' => 'Entrega por correio/transportadora. Não há link digital de acesso.',
            'primary_url' => null,
            'checkout_url' => $checkoutUrl,
            'can_open' => false,
            'limitations' => 'Verifique descrição e imagens do produto no checkout, se necessário.',
        ];
    }

    private function legacyAppPreview(?string $checkoutUrl): array
    {
        return [
            'kind' => 'none',
            'title' => 'Aplicativo',
            'description' => 'Tipo legado (aplicativo). Sem preview de entregável disponível.',
            'primary_url' => null,
            'checkout_url' => $checkoutUrl,
            'can_open' => false,
            'limitations' => null,
        ];
    }

    private function checkoutUrl(Product $product): ?string
    {
        $slug = trim((string) ($product->checkout_slug ?? ''));
        if ($slug === '') {
            return null;
        }

        return url('/c/'.$slug);
    }
}
