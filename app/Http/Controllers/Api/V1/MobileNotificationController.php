<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->mobileNotifications()->latest()->paginate(30);

        return response()->json([
            'data' => collect($notifications->items())->map(fn (MobileNotification $notification): array => $this->payload($notification)),
            'meta' => ['current_page' => $notifications->currentPage(), 'last_page' => $notifications->lastPage(), 'total' => $notifications->total(), 'unread' => $request->user()->mobileNotifications()->whereNull('read_at')->count()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $model = MobileNotification::query()->where('uuid', $notification)->where('user_id', $request->user()->id)->firstOrFail();
        $model->forceFill(['read_at' => $model->read_at ?? now()])->save();

        return response()->json(['data' => $this->payload($model)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->mobileNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(status: 204);
    }

    private function payload(MobileNotification $notification): array
    {
        return [
            'id' => $notification->uuid,
            'event' => $notification->event_key,
            'category' => $notification->category,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data ?? [],
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }
}
