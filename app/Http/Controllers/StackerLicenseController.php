<?php

namespace App\Http\Controllers;

use App\Services\Stacker\LicenseService;
use Inertia\Inertia;
use Inertia\Response;

class StackerLicenseController extends Controller
{
    public function support(LicenseService $license): Response
    {
        $whatsapp = $license->supportWhatsappUrl();

        return Inertia::render('Stacker/LicenseSupport', [
            'whatsappUrl' => $whatsapp,
            'supportPhone' => config('getfy.stacker.support_whatsapp'),
        ]);
    }
}
