<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobileDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $drafts = $request->user()->mobileDrafts()->where('status', 'active')->latest('last_activity_at')->get()->map(fn (MobileDraft $draft) => $this->payload($draft));

        return response()->json(['data' => $drafts])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $childId = $this->resolveChild($request, $validated['child_profile_id'] ?? null);
        $draft = $request->user()->mobileDrafts()->create([
            'child_profile_id' => $childId,
            'draft_type' => $validated['draft_type'],
            'status' => 'active',
            'payload' => $validated['payload'],
            'version' => 1,
            'last_activity_at' => now(),
        ]);

        return response()->json(['data' => $this->payload($draft)], 201);
    }

    public function show(Request $request, MobileDraft $draft): JsonResponse
    {
        $this->authorizeOwner($request, $draft);

        return response()->json(['data' => $this->payload($draft)])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, MobileDraft $draft): JsonResponse
    {
        $this->authorizeOwner($request, $draft);
        $validated = $request->validate([
            'payload' => ['required', 'array'],
            'version' => ['required', 'integer', 'min:1'],
            'child_profile_id' => ['nullable', 'uuid'],
        ]);
        if ((int) $draft->version !== (int) $validated['version']) {
            return response()->json(['message' => 'The draft changed on another device.', 'data' => $this->payload($draft)], 409);
        }
        $draft->update([
            'child_profile_id' => array_key_exists('child_profile_id', $validated)
                ? $this->resolveChild($request, $validated['child_profile_id'])
                : $draft->child_profile_id,
            'payload' => $validated['payload'],
            'version' => $draft->version + 1,
            'last_activity_at' => now(),
        ]);

        return response()->json(['data' => $this->payload($draft->fresh())]);
    }

    public function destroy(Request $request, MobileDraft $draft): JsonResponse
    {
        $this->authorizeOwner($request, $draft);
        $draft->forceFill(['status' => 'abandoned'])->save();
        $draft->delete();

        return response()->json(status: 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'draft_type' => ['required', Rule::in(['personalization', 'cart', 'identity'])],
            'child_profile_id' => ['nullable', 'uuid'],
            'payload' => ['required', 'array'],
        ]);
    }

    private function resolveChild(Request $request, ?string $uuid): ?int
    {
        return $uuid ? $request->user()->childProfiles()->where('uuid', $uuid)->firstOrFail()->id : null;
    }

    private function authorizeOwner(Request $request, MobileDraft $draft): void
    {
        abort_unless($draft->user_id === $request->user()->id, 404);
    }

    private function payload(MobileDraft $draft): array
    {
        return [
            'id' => $draft->uuid,
            'type' => $draft->draft_type,
            'status' => $draft->status,
            'child_profile_id' => $draft->childProfile?->uuid,
            'payload' => $draft->payload,
            'version' => $draft->version,
            'last_activity_at' => $draft->last_activity_at?->toISOString(),
        ];
    }
}
