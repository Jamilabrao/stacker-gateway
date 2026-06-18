<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Api\ApiAuthContext;
use App\Services\Api\ApiIdempotencyService;
use App\Services\Api\ApiWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WithdrawalsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::WITHDRAWALS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $query = Withdrawal::query()
            ->where('tenant_id', $ctx->application->tenant_id)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Withdrawal $w) => $this->serializeWithdrawal($w)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, int $withdrawal): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::WITHDRAWALS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        $model = Withdrawal::query()
            ->where('tenant_id', $ctx->application->tenant_id)
            ->whereKey($withdrawal)
            ->first();

        if ($model === null) {
            return response()->json(['message' => 'Saque não encontrado.'], 404);
        }

        return response()->json($this->serializeWithdrawal($model));
    }

    public function store(Request $request, ApiIdempotencyService $idempotency, ApiWithdrawalService $withdrawals): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::WITHDRAWALS_WRITE)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'bucket' => ['nullable', 'string', 'in:pix,card,boleto'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $idemKey = $request->input('idempotency_key') ?: $request->header('Idempotency-Key');

        return $idempotency->handle(
            $ctx->application,
            $ctx->apiKey,
            is_string($idemKey) ? $idemKey : null,
            $request->getContent() ?: json_encode($validated),
            function () use ($ctx, $validated, $withdrawals) {
                try {
                    $owner = ApiWithdrawalService::resolveInfoprodutor((int) $ctx->application->tenant_id);
                    $withdrawal = $withdrawals->createWithdrawal(
                        $owner,
                        (float) $validated['amount'],
                        (string) ($validated['bucket'] ?? 'pix'),
                        (int) $ctx->application->id,
                        $ctx->apiKey?->id,
                        $validated['notes'] ?? null,
                    );
                    $withdrawals->recordVelocity((int) $ctx->application->tenant_id, (float) $validated['amount']);

                    return response()->json($this->serializeWithdrawal($withdrawal), 201);
                } catch (ValidationException $e) {
                    return response()->json([
                        'message' => collect($e->errors())->flatten()->first() ?: 'Validation failed.',
                        'errors' => $e->errors(),
                    ], 422);
                }
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWithdrawal(Withdrawal $withdrawal): array
    {
        return [
            'withdrawal_id' => $withdrawal->id,
            'status' => $withdrawal->status,
            'amount' => (float) $withdrawal->amount,
            'fee_amount' => (float) $withdrawal->fee_amount,
            'net_amount' => (float) $withdrawal->net_amount,
            'bucket' => $withdrawal->bucket,
            'failed_reason' => $withdrawal->failed_reason,
            'notes' => $withdrawal->notes,
            'payout_provider' => $withdrawal->payout_provider,
            'payout_external_id' => $withdrawal->payout_external_id,
            'created_at' => $withdrawal->created_at?->toIso8601String(),
            'updated_at' => $withdrawal->updated_at?->toIso8601String(),
        ];
    }
}
