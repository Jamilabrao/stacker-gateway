<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SellerDashboardTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SellerDashboardTemplateController extends Controller
{
    public function data(): JsonResponse
    {
        return response()->json([
            'template' => SellerDashboardTemplate::current(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(SellerDashboardTemplate::allowed())],
        ]);

        Setting::set(
            SellerDashboardTemplate::KEY,
            SellerDashboardTemplate::resolve($validated['template']),
            null
        );

        return response()->json([
            'ok' => true,
            'template' => SellerDashboardTemplate::current(),
        ]);
    }
}
