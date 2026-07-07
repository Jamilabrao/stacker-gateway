<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SalesAchievement;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesAchievementsController extends Controller
{
    public function __construct(
        protected StorageService $storage,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Platform/Conquistas/Index', [
            'achievements' => SalesAchievement::query()
                ->orderBy('sort_order')
                ->orderBy('threshold')
                ->get()
                ->map(fn (SalesAchievement $a) => [
                    'id' => $a->id,
                    'slug' => $a->slug,
                    'name' => $a->name,
                    'threshold' => (float) $a->threshold,
                    'image' => $this->resolveImageUrl($a->image),
                    'sort_order' => (int) $a->sort_order,
                    'is_active' => (bool) $a->is_active,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', 'unique:sales_achievements,slug'],
            'name' => ['required', 'string', 'max:180'],
            'threshold' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $achievement = SalesAchievement::query()->create([
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'threshold' => (float) $validated['threshold'],
            'image' => $this->normalizeImageInput($validated['image'] ?? null),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json(['ok' => true, 'achievement' => $achievement]);
    }

    public function update(Request $request, SalesAchievement $salesAchievement): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('sales_achievements', 'slug')->ignore($salesAchievement->id)],
            'name' => ['required', 'string', 'max:180'],
            'threshold' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $salesAchievement->update([
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'threshold' => (float) $validated['threshold'],
            'image' => $this->normalizeImageInput($validated['image'] ?? null),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(SalesAchievement $salesAchievement): JsonResponse
    {
        $salesAchievement->delete();

        return response()->json(['ok' => true]);
    }

    public function uploadImage(Request $request, SalesAchievement $salesAchievement): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp,gif,svg'],
        ]);

        $uploaded = $this->storage->storeUploadedPublicFile($request->file('file'), 'conquistas');
        $salesAchievement->update(['image' => $uploaded['path']]);

        return response()->json(['ok' => true, 'url' => $uploaded['url']]);
    }

    private function resolveImageUrl(mixed $stored): ?string
    {
        if (! is_string($stored) || trim($stored) === '') {
            return null;
        }

        $url = $this->storage->resolvePublicUrl($stored);

        return $url !== '' ? $url : null;
    }

    private function normalizeImageInput(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->storage->toStoragePath($value) ?? trim($value);
    }
}
