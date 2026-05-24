<?php

namespace App\Http\Controllers;

use App\Services\LegalDocumentsService;
use Inertia\Inertia;
use Inertia\Response;

class LegalPagesController extends Controller
{
    public function __construct(
        private readonly LegalDocumentsService $legalDocuments
    ) {}

    public function privacy(): Response
    {
        return Inertia::render('Legal/Privacy', [
            'title' => 'Política de Privacidade',
            'html' => $this->legalDocuments->sanitizedPrivacyHtml(),
            'updated_at' => $this->legalDocuments->privacyUpdatedAt(),
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('Legal/Terms', [
            'title' => 'Termos de Uso',
            'html' => $this->legalDocuments->sanitizedTermsHtml(),
            'updated_at' => $this->legalDocuments->termsUpdatedAt(),
        ]);
    }
}
