<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileUpload;
use App\Services\Mobile\MobileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileUploadController extends Controller
{
    public function store(Request $request, MobileUploadService $uploads): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(['child_reference', 'identity', 'personalization', 'review'])],
            'child_profile_id' => ['nullable', 'uuid'],
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', Rule::in(config('photo_uploads.allowed_mimes', []))],
            'size' => ['required', 'integer', 'min:1', 'max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024 * 1024)],
        ]);
        $child = null;
        if (! empty($validated['child_profile_id'])) {
            $child = $request->user()->childProfiles()->where('uuid', $validated['child_profile_id'])->firstOrFail();
        }
        if ($validated['purpose'] === 'child_reference' && ! $child) {
            return response()->json(['message' => 'A child profile is required.', 'errors' => ['child_profile_id' => ['A child profile is required.']]], 422);
        }

        return response()->json(['data' => $this->payload($uploads->initiate($request->user(), $validated, $child))], 201);
    }

    public function show(Request $request, MobileUpload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        return response()->json(['data' => $this->payload($upload)]);
    }

    public function chunk(Request $request, MobileUpload $upload, int $index, MobileUploadService $uploads): JsonResponse
    {
        $this->authorizeOwner($request, $upload);
        $request->validate([
            'chunk' => ['required', 'file', 'max:2048'],
            'checksum' => ['nullable', 'string', 'size:64'],
        ]);
        $upload = $uploads->storeChunk($upload, $index, $request->file('chunk'), $request->input('checksum'));

        return response()->json(['data' => $this->payload($upload)]);
    }

    public function attach(Request $request, MobileUpload $upload, MobileUploadService $uploads): JsonResponse
    {
        $this->authorizeOwner($request, $upload);
        $validated = $request->validate([
            'child_profile_id' => ['required', 'uuid'],
            'reuse_consent' => ['required', 'accepted'],
        ]);
        $child = $request->user()->childProfiles()->where('uuid', $validated['child_profile_id'])->firstOrFail();
        $photo = $uploads->attachToChild($upload, $child);
        DB::table('consent_records')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'child_profile_id' => $child->id,
            'consent_type' => 'child_photo_reuse',
            'document_version' => 'child-photo-reuse-v1-2026-08',
            'granted' => true,
            'recorded_at' => now(),
            'source' => 'mobile',
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            'metadata' => json_encode(['upload_uuid' => $upload->uuid, 'photo_uuid' => $photo->uuid], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'id' => $photo->uuid,
            'mime_type' => $photo->mime_type,
            'width' => $photo->width,
            'height' => $photo->height,
            'created_at' => $photo->created_at?->toISOString(),
        ]], 201);
    }

    public function destroy(Request $request, MobileUpload $upload, MobileUploadService $uploads): JsonResponse
    {
        $this->authorizeOwner($request, $upload);
        $uploads->delete($upload);

        return response()->json(status: 204);
    }

    private function authorizeOwner(Request $request, MobileUpload $upload): void
    {
        abort_unless($upload->user_id === $request->user()->id, 404);
    }

    private function payload(MobileUpload $upload): array
    {
        return [
            'id' => $upload->uuid,
            'purpose' => $upload->purpose,
            'filename' => $upload->original_filename,
            'mime_type' => $upload->verified_mime_type ?: $upload->declared_mime_type,
            'expected_size' => $upload->expected_size,
            'received_size' => $upload->received_size,
            'chunk_size' => $upload->chunk_size,
            'received_chunks' => array_map('intval', array_keys($upload->chunks ?? [])),
            'status' => $upload->status,
            'progress' => $upload->expected_size > 0 ? round($upload->received_size / $upload->expected_size, 4) : 0,
            'error' => $upload->safe_error_message,
            'expires_at' => $upload->expires_at?->toISOString(),
        ];
    }
}
