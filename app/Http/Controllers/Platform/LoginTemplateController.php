<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\LoginTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoginTemplateController extends Controller
{
    public function data(): JsonResponse
    {
        return response()->json([
            'template' => LoginTemplate::current(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(LoginTemplate::allowed())],
        ]);

        Setting::set(
            LoginTemplate::KEY,
            LoginTemplate::resolve($validated['template']),
            null
        );

        return response()->json([
            'ok' => true,
            'template' => LoginTemplate::current(),
        ]);
    }
}
