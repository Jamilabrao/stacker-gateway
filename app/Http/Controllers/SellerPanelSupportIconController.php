<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerPanelSupportIconController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->canAccessPlatformPanel()) {
            abort(403);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp,svg'],
        ]);

        // Storage global (tenant null): o ícone é da plataforma, não do tenant do admin/equipe.
        $storage = new StorageService(null);
        $uploaded = $storage->storeUploadedPublicFile($request->file('file'), 'seller-panel-support');

        Setting::set('seller_panel_support_icon_image', $uploaded['path'], null);
        Setting::set('seller_panel_support_icon', 'custom', null);

        return response()->json(['ok' => true, 'url' => $uploaded['url']]);
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
