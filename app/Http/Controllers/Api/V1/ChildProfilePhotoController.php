<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChildProfile;
use App\Models\ChildProfilePhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChildProfilePhotoController extends Controller
{
    public function index(Request $request, ChildProfile $child): JsonResponse
    {
        $this->authorizeChild($request, $child);

        return response()->json(['data' => $child->activePhotos->map(fn (ChildProfilePhoto $photo): array => $this->payload($child, $photo))]);
    }

    public function media(Request $request, ChildProfile $child, ChildProfilePhoto $photo)
    {
        $this->authorizeChild($request, $child);
        abort_unless($photo->child_profile_id === $child->id && $photo->status === 'active', 404);
        abort_if(str_contains($photo->path, '..') || ! Storage::disk($photo->disk)->exists($photo->path), 404);

        return Storage::disk($photo->disk)->response($photo->path, null, [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function destroy(Request $request, ChildProfile $child, ChildProfilePhoto $photo): JsonResponse
    {
        $this->authorizeChild($request, $child);
        abort_unless($photo->child_profile_id === $child->id, 404);
        $photo->forceFill(['status' => 'deleted', 'deleted_at' => now()])->save();
        Storage::disk($photo->disk)->delete($photo->path);
        DB::table('consent_records')->insert([
            'user_id' => $request->user()->id,
            'child_profile_id' => $child->id,
            'consent_type' => 'child_profile_photo_deleted',
            'document_version' => 'privacy-v1-2026-08',
            'granted' => false,
            'recorded_at' => now(),
            'source' => 'mobile',
            'metadata' => json_encode(['photo_uuid' => $photo->uuid], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(status: 204);
    }

    private function authorizeChild(Request $request, ChildProfile $child): void
    {
        abort_unless($child->user_id === $request->user()->id, 404);
    }

    private function payload(ChildProfile $child, ChildProfilePhoto $photo): array
    {
        return [
            'id' => $photo->uuid,
            'mime_type' => $photo->mime_type,
            'width' => $photo->width,
            'height' => $photo->height,
            'media_url' => route('api.v1.children.photos.media', [$child, $photo]),
            'created_at' => $photo->created_at?->toISOString(),
        ];
    }
}
