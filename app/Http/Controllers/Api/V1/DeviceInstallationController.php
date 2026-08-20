<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceInstallation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceInstallationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->deviceInstallations()->latest('last_seen_at')->get()->map(fn (DeviceInstallation $device): array => $this->payload($device))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'app_version' => ['nullable', 'string', 'max:40'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'timezone' => ['nullable', 'string', 'max:80'],
            'push_token' => ['nullable', 'string', 'max:500', 'regex:/^(Expo(nent)?PushToken)\[[A-Za-z0-9_-]+\]$/'],
            'marketing_notifications' => ['sometimes', 'boolean'],
            'operational_notifications' => ['sometimes', 'boolean'],
        ]);

        $device = DeviceInstallation::query()->where('uuid', $data['installation_id'])->first();
        abort_if($device && $device->user_id !== $request->user()->id, 409, 'This installation belongs to another account.');
        $token = $data['push_token'] ?? null;
        $device = DeviceInstallation::query()->updateOrCreate(
            ['uuid' => $data['installation_id']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'locale' => $data['locale'],
                'timezone' => $data['timezone'] ?? null,
                'push_token' => $token,
                'push_token_hash' => $token ? hash('sha256', $token) : null,
                'marketing_notifications' => $data['marketing_notifications'] ?? $device?->marketing_notifications ?? false,
                'operational_notifications' => $data['operational_notifications'] ?? $device?->operational_notifications ?? true,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        return response()->json(['data' => $this->payload($device)], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $device): JsonResponse
    {
        $installation = DeviceInstallation::query()->where('uuid', $device)->where('user_id', $request->user()->id)->firstOrFail();
        $data = $request->validate([
            'marketing_notifications' => ['sometimes', 'boolean'],
            'operational_notifications' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
        ]);
        $installation->update($data + ['last_seen_at' => now()]);

        return response()->json(['data' => $this->payload($installation->fresh())]);
    }

    public function destroy(Request $request, string $device): JsonResponse
    {
        $installation = DeviceInstallation::query()->where('uuid', $device)->where('user_id', $request->user()->id)->firstOrFail();
        $installation->forceFill(['revoked_at' => now(), 'push_token' => null, 'push_token_hash' => null])->save();

        return response()->json(status: 204);
    }

    private function payload(DeviceInstallation $device): array
    {
        return [
            'id' => $device->uuid,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'device_name' => $device->device_name,
            'locale' => $device->locale,
            'push_enabled' => $device->push_token_hash !== null && $device->revoked_at === null,
            'marketing_notifications' => $device->marketing_notifications,
            'operational_notifications' => $device->operational_notifications,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'revoked_at' => $device->revoked_at?->toISOString(),
        ];
    }
}
