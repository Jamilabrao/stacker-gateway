<?php

namespace App\Services\Docs;

use Illuminate\Support\Str;

class ApiPagamentosLlmBundle
{
    /**
     * Nome do arquivo de download com base no nome da plataforma (admin → branding).
     */
    public function downloadFilename(?string $appName = null): string
    {
        $slug = Str::slug($appName ?? (string) config('getfy.app_name', 'getfy'), '-');

        if ($slug === '') {
            $slug = 'plataforma';
        }

        return "{$slug}-api-integracao-llm.md";
    }

    /**
     * Monta o pacote Markdown completo para LLMs (instruções + fallbacks + referência API).
     */
    public function build(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $parts = [];

        foreach ($this->modulePaths() as $path) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException("Não foi possível ler o módulo de documentação: {$path}");
            }
            $parts[] = $this->replacePlaceholders($content, $baseUrl);
        }

        $apiDoc = file_get_contents(base_path('docs-api.md'));
        if ($apiDoc === false) {
            throw new \RuntimeException('Não foi possível ler docs-api.md');
        }
        $parts[] = $this->replacePlaceholders($apiDoc, $baseUrl);

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function modulePaths(): array
    {
        return [
            base_path('docs/llm/00-instructions.md'),
            base_path('docs/llm/10-payment-confirmation-fallbacks.md'),
        ];
    }

    private function replacePlaceholders(string $content, string $baseUrl): string
    {
        return str_replace(
            ['{{BASE_URL}}', 'https://seudominio.com'],
            $baseUrl,
            $content
        );
    }
}
