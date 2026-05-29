<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\PanelColorScheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PanelColorSchemeController extends Controller
{
    public function data(): JsonResponse
    {
        return response()->json(PanelColorScheme::current());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(PanelColorScheme::allowedModes())],
            'locked' => ['required', 'boolean'],
        ]);

        $scheme = PanelColorScheme::normalize($validated);

        Setting::set(PanelColorScheme::KEY, $scheme, null);
        PanelColorScheme::applyToConfig();

        return response()->json([
            'ok' => true,
            ...$scheme,
        ]);
    }
}
