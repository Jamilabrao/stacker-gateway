<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Services\Docs\ApiPagamentosLlmBundle;
use Tests\TestCase;

class ApiPagamentosLlmBundleTest extends TestCase
{
    public function test_llm_bundle_download_returns_markdown(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $response = $this->get(route('api-docs.pagamentos.llm'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/markdown; charset=UTF-8');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertNotEmpty($response->headers->get('content-length'));

        $content = $response->getContent();
        $this->assertSame((string) strlen((string) $content), $response->headers->get('content-length'));
        $this->assertStringContainsString('# Instruções para o modelo de IA', $content);
        $this->assertStringContainsString('# API de Pagamentos e Saques', $content);
        $this->assertStringContainsString('# Confirmação de pagamento e fallbacks', $content);
    }

    public function test_llm_bundle_legacy_full_md_url_still_works(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $this->get('/docs/api-pagamentos/llm/full.md')->assertOk();
    }

    public function test_llm_bundle_service_builds_with_base_url_placeholder(): void
    {
        $bundle = app(ApiPagamentosLlmBundle::class);
        $content = $bundle->build('https://gateway.exemplo.com');

        $this->assertStringContainsString('https://gateway.exemplo.com/api/v1', $content);
        $this->assertStringNotContainsString('https://seudominio.com', $content);
    }
}
