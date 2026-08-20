<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChildProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ChildProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $children = $request->user()->childProfiles()->latest()->get()->map(fn (ChildProfile $child) => $this->payload($child));

        return response()->json(['data' => $children]);
    }

    public function store(Request $request): JsonResponse
    {
        $child = $request->user()->childProfiles()->create($this->validated($request));

        return response()->json(['data' => $this->payload($child)], 201);
    }

    public function show(Request $request, ChildProfile $child): JsonResponse
    {
        $this->authorizeOwner($request, $child);

        return response()->json(['data' => $this->payload($child)]);
    }

    public function update(Request $request, ChildProfile $child): JsonResponse
    {
        $this->authorizeOwner($request, $child);
        $child->update($this->validated($request, true));

        return response()->json(['data' => $this->payload($child->fresh())]);
    }

    public function destroy(Request $request, ChildProfile $child): JsonResponse
    {
        $this->authorizeOwner($request, $child);
        $photos = $child->activePhotos()->get();
        DB::transaction(function () use ($request, $child, $photos): void {
            foreach ($photos as $photo) {
                $photo->forceFill(['status' => 'deleted', 'deleted_at' => now()])->save();
            }
            $child->forceFill(['is_active' => false])->save();
            $child->delete();
            DB::table('consent_records')->insert([
                'user_id' => $request->user()->id,
                'child_profile_id' => null,
                'consent_type' => 'child_profile_deleted',
                'document_version' => 'privacy-v1-2026-08',
                'granted' => false,
                'recorded_at' => now(),
                'source' => 'mobile',
                'metadata' => json_encode(['child_profile_uuid' => $child->uuid, 'reusable_photo_count' => $photos->count()], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        foreach ($photos as $photo) {
            Storage::disk($photo->disk)->delete($photo->path);
        }
        if ($child->profile_photo_path) {
            Storage::disk($child->profile_photo_disk ?: 'local')->delete($child->profile_photo_path);
        }

        return response()->json(status: 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'age' => ['nullable', 'integer', 'min:0', 'max:17'],
            'gender' => ['nullable', Rule::in(['boy', 'girl', 'other', 'prefer_not_to_say'])],
            'interests' => ['nullable', 'array', 'max:20'],
            'interests.*' => ['string', 'max:80'],
            'preferred_language' => ['nullable', Rule::in(['ar', 'en'])],
            'photo_reuse_consent' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('photo_reuse_consent', $validated)) {
            $validated['photo_reuse_consent_at'] = $validated['photo_reuse_consent'] ? now() : null;
            unset($validated['photo_reuse_consent']);
        }

        return $validated;
    }

    private function authorizeOwner(Request $request, ChildProfile $child): void
    {
        abort_unless($child->user_id === $request->user()->id, 404);
    }

    private function payload(ChildProfile $child): array
    {
        return [
            'id' => $child->uuid,
            'name' => $child->name,
            'birth_date' => $child->birth_date?->toDateString(),
            'age' => $child->age,
            'gender' => $child->gender,
            'interests' => $child->interests ?? [],
            'preferred_language' => $child->preferred_language,
            'has_profile_photo' => filled($child->profile_photo_path),
            'photo_reuse_consent' => $child->photo_reuse_consent_at !== null,
            'created_at' => $child->created_at?->toISOString(),
        ];
    }
}
