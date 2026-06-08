<?php

namespace App\Http\Controllers;

use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelNotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $perPage = min((int) $request->input('per_page', 20), 50);
        $notifications = PanelNotification::forUser($user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $unreadCount = PanelNotification::forUser($user->id)->unread()->count();
        $pushStatus = PanelPushSubscription::pushStatusForUser(
            (int) $user->id,
            $user->tenant_id !== null ? (int) $user->tenant_id : null
        );

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
            'unread_count' => $unreadCount,
            'push_subscribed' => $pushStatus['push_subscribed'],
            'push_needs_resubscribe' => $pushStatus['push_needs_resubscribe'],
        ]);
    }

    public function markRead(Request $request, PanelNotification $notification): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel() || $notification->user_id !== $user->id) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markReadBatch(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            return response()->json(['message' => 'ids deve ser um array.'], 422);
        }

        PanelNotification::forUser($user->id)
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        PanelNotification::forUser($user->id)->unread()->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $deleted = PanelNotification::forUser($user->id)->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }
}
