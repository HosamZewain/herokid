<?php

namespace App\Services\Mobile;

use App\Models\MobileAnalyticsEvent;
use App\Support\MobileAnalyticsEventRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MobileAnalyticsRecorder
{
    public function record(Request $request, string $event, array $properties = []): MobileAnalyticsEvent
    {
        return MobileAnalyticsEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $request->user('sanctum')?->id ?? auth('sanctum')->id(),
            'device_installation_uuid' => $request->header('X-Device-Installation'),
            'event_name' => $event,
            'properties' => MobileAnalyticsEventRegistry::sanitize($properties),
            'app_version' => $request->header('X-App-Version'),
            'platform' => $request->header('X-Platform'),
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }
}
