<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SellerPanelSupportIconController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->canAccessPlatformPanel()) {
            abort(403);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp,svg'],
        ]);

        $path = $request->file('file')->store('seller-panel-support', 'public');
        $url = Storage::disk('public')->url($path);
        if (! str_starts_with($url, 'http')) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        Setting::set('seller_panel_support_icon_image', $url, null);
        Setting::set('seller_panel_support_icon', 'custom', null);

        return response()->json(['ok' => true, 'url' => $url]);
    }

    public function clearIcon(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->canAccessPlatformPanel()) {
            abort(403);
        }

        Setting::set('seller_panel_support_icon_image', '', null);

        return response()->json(['ok' => true]);
    }
}
