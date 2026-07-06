<?php

namespace App\Http\Controllers;

use App\Services\Docs\ApiPagamentosLlmBundle;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ApiDocsController extends Controller
{
    public function __invoke(): Response
    {
        $baseUrl = rtrim(url('/'), '/');

        return Inertia::render('Docs/ApiPagamentos', [
            'baseUrl' => $baseUrl,
            'pageTitle' => 'Documentação da API',
            'docSubtitle' => 'Integração com o gateway',
            'layoutFullWidth' => true,
            'publicMode' => true,
        ]);
    }

    /**
     * Hub de integração via IA (download do pacote Markdown para LLMs).
     */
    public function ia(ApiPagamentosLlmBundle $bundle): Response
    {
        $baseUrl = rtrim(url('/'), '/');

        return Inertia::render('Docs/ApiPagamentosIa', [
            'baseUrl' => $baseUrl,
            'llmBundleFilename' => $bundle->downloadFilename(),
            'pageTitle' => 'Integrar com IA',
            'docSubtitle' => 'Hub de documentação para LLMs',
            'layoutFullWidth' => true,
            'publicMode' => true,
        ]);
    }

    /**
     * Download do pacote Markdown completo para LLMs.
     */
    public function llmBundle(ApiPagamentosLlmBundle $bundle): HttpResponse
    {
        $baseUrl = rtrim(url('/'), '/');

        try {
            $content = $bundle->build($baseUrl);
        } catch (\RuntimeException $e) {
            abort(404, 'Pacote de documentação indisponível. '.$e->getMessage());
        }

        $filename = $bundle->downloadFilename();

        return response($content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Playground interativo para testar endpoints PIX e saques.
     */
    public function testar(): Response
    {
        $baseUrl = rtrim(url('/'), '/');

        return Inertia::render('Docs/ApiPagamentosTestar', [
            'baseUrl' => $baseUrl,
            'pageTitle' => 'Testar API',
            'docSubtitle' => 'Sandbox de endpoints',
            'publicMode' => true,
        ]);
    }
}
