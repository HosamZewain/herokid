<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileAnalyticsEvent;
use App\Support\MobileAnalyticsEventRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileAnalyticsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.id' => ['required', 'uuid'],
            'events.*.name' => ['required', Rule::in(MobileAnalyticsEventRegistry::EVENTS)],
            'events.*.properties' => ['nullable', 'array', 'max:50'],
            'events.*.occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', Rule::in(['ios', 'android'])],
        ]);
        $userId = auth('sanctum')->id();
        $device = $request->header('X-Device-Installation');
        $accepted = 0;

        DB::transaction(function () use ($data, $userId, $device, &$accepted): void {
            foreach ($data['events'] as $event) {
                $model = MobileAnalyticsEvent::query()->firstOrCreate(
                    ['event_uuid' => $event['id']],
                    [
                        'user_id' => $userId,
                        'device_installation_uuid' => $device,
                        'event_name' => $event['name'],
                        'properties' => MobileAnalyticsEventRegistry::sanitize($event['properties'] ?? []),
                        'app_version' => $data['app_version'] ?? null,
                        'platform' => $data['platform'] ?? null,
                        'occurred_at' => $event['occurred_at'],
                        'received_at' => now(),
                    ],
                );
                $accepted += $model->wasRecentlyCreated ? 1 : 0;
            }
        });

        return response()->json(['data' => ['accepted' => $accepted]], 202);
    }
}
