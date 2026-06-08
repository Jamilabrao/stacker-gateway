<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MerchantAdminNote;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MerchantAdminNotesController extends Controller
{
    public function index(User $user): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $notes = MerchantAdminNote::query()
            ->where('merchant_user_id', $user->id)
            ->with('author:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'notes' => $notes->map(fn (MerchantAdminNote $n) => $this->notePayload($n)),
        ]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:3', 'max:5000'],
        ], [
            'body.required' => 'Informe o texto da observação.',
            'body.min' => 'A observação deve ter pelo menos 3 caracteres.',
            'body.max' => 'A observação pode ter no máximo 5000 caracteres.',
        ]);

        $body = HtmlSanitizer::plainTextMultiline($validated['body'], 5000);
        if ($body === '' || mb_strlen($body) < 3) {
            return response()->json([
                'message' => 'A observação deve ter pelo menos 3 caracteres.',
            ], 422);
        }

        $note = MerchantAdminNote::query()->create([
            'merchant_user_id' => $user->id,
            'author_user_id' => $request->user()->id,
            'body' => $body,
            'created_at' => now(),
        ]);

        $note->load('author:id,name');

        PlatformAuditService::log('platform.merchant.note_created', [
            'merchant_user_id' => $user->id,
            'note_id' => $note->id,
        ], $request);

        return response()->json([
            'note' => $this->notePayload($note),
        ], 201);
    }

    /**
     * @return array{id: int, body: string, created_at: string|null, author: array{id: int, name: string}|null}
     */
    private function notePayload(MerchantAdminNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'created_at' => $note->created_at?->toIso8601String(),
            'author' => $note->author ? [
                'id' => $note->author->id,
                'name' => $note->author->name,
            ] : null,
        ];
    }
}
