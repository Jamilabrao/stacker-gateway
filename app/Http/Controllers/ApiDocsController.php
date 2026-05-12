<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ApiDocsController extends Controller
{
    public function __invoke(): Response
    {
        $baseUrl = rtrim(url('/'), '/');

        return Inertia::render('Docs/ApiPagamentos', [
            'baseUrl' => $baseUrl,
            'pageTitle' => 'Documentação da API PIX (Gateway)',
            'layoutFullWidth' => true,
            'publicMode' => true,
        ]);
    }

    /**
     * Página provisória para testar a API de pagamentos (POST/GET nos endpoints).
     */
    public function testar(): Response
    {
        $baseUrl = rtrim(url('/'), '/');

        return Inertia::render('Docs/ApiPagamentosTestar', [
            'baseUrl' => $baseUrl,
            'publicMode' => true,
        ]);
    }
}
