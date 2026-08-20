<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Services\ChildIdentity\AgeRangeResolver;
use App\Services\ChildIdentity\ChildIdentityApprovalService;
use App\Services\ChildIdentity\ChildIdentityAttemptService;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Services\ChildIdentity\ChildIdentityPhotoService;
use App\Services\ChildIdentity\ChildIdentitySettings;
use App\Services\Mobile\MobileChildIdentityDeletionService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MobileChildIdentityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $identities = ChildIdentityRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['attempts', 'approvedAttempt'])
            ->latest('last_activity_at')
            ->get()
            ->map(fn (ChildIdentityRequest $identity): array => $this->payload($identity));

        return response()->json(['data' => $identities])->header('Cache-Control', 'private, no-store');
    }

    public function store(
        Request $request,
        ChildIdentitySettings $settings,
        AgeRangeResolver $ageRanges,
        ChildIdentityPhotoService $photos,
        ChildIdentityEventLogger $events,
        ChildIdentityAttemptService $attempts,
    ): JsonResponse {
        abort_unless($settings->enabled(), 404);
        $validated = $request->validate([
            'child_profile_id' => ['required', 'uuid'],
            'photo_ids' => ['required', 'array', 'min:2', 'max:3'],
            'photo_ids.*' => ['required', 'uuid', 'distinct'],
            'identity_type' => ['required', Rule::in(['original', 'story_inspired', 'themed'])],
            'theme' => ['nullable', 'required_if:identity_type,themed', Rule::in(['astronaut', 'doctor', 'pilot', 'knight', 'football_player', 'princess', 'explorer'])],
            'processing_consent' => ['required', 'accepted'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $existing = ChildIdentityRequest::query()
            ->where('user_id', $request->user()->id)
            ->where('mobile_idempotency_key', $validated['idempotency_key'])
            ->with(['attempts', 'approvedAttempt'])
            ->first();
        if ($existing) {
            return response()->json(['data' => $this->payload($existing)]);
        }

        $child = $request->user()->childProfiles()->where('uuid', $validated['child_profile_id'])->firstOrFail();
        $selectedPhotos = $child->activePhotos()->whereIn('uuid', $validated['photo_ids'])->get()->keyBy('uuid');
        abort_unless($selectedPhotos->count() === count($validated['photo_ids']), 422, 'One or more selected photos are unavailable.');
        $age = $child->age ?? $child->birth_date?->age;
        abort_unless(is_int($age), 422, 'Complete the child age before generating an identity.');
        $orderedPhotos = collect($validated['photo_ids'])->map(fn (string $uuid) => $selectedPhotos->get($uuid));

        $identity = DB::transaction(function () use ($request, $validated, $child, $age, $ageRanges, $orderedPhotos, $photos, $events): ChildIdentityRequest {
            $identity = ChildIdentityRequest::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $request->user()->id,
                'child_profile_id' => $child->id,
                'mobile_idempotency_key' => $validated['idempotency_key'],
                'resume_token_hash' => hash('sha256', Str::random(80)),
                'parent_name' => $request->user()->name,
                'parent_phone' => Phone::normalize($request->user()->phone) ?: 'mobile-account',
                'parent_email' => $request->user()->email,
                'child_name' => $child->name,
                'child_age' => $age,
                'age_range' => $ageRanges->resolve($age),
                'gender' => in_array($child->gender, ['boy', 'girl'], true) ? $child->gender : null,
                'identity_type' => $validated['identity_type'],
                'identity_theme' => $validated['theme'] ?? null,
                'status' => 'incomplete',
                'consent_accepted_at' => now(),
                'consent_version' => 'child-identity-mobile-v1-2026-08',
                'marketing_consent_at' => ($validated['marketing_consent'] ?? false) ? now() : null,
                'last_activity_at' => now(),
            ]);
            $events->record($identity, 'request.created', 'Mobile Child Identity request created.', actor: $request->user(), source: 'mobile');
            $stored = $photos->adoptChildProfilePhotos($identity, $orderedPhotos);
            $identity->forceFill(['status' => 'photos_uploaded'])->save();
            $events->record($identity, 'photos.batch_uploaded', 'Reusable child profile photos were copied into the identity request.', ['photos_count' => $stored->count()], actor: $request->user(), source: 'mobile', fromStatus: 'incomplete', toStatus: 'photos_uploaded');
            DB::table('consent_records')->insert([
                'user_id' => $request->user()->id,
                'child_profile_id' => $child->id,
                'consent_type' => 'child_image_processing',
                'document_version' => 'child-identity-mobile-v1-2026-08',
                'granted' => true,
                'recorded_at' => now(),
                'source' => 'mobile',
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                'metadata' => json_encode(['identity_uuid' => $identity->uuid], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $identity;
        });

        $attempts->create($identity, $validated['idempotency_key'], 'customer', $request->user());

        return response()->json(['data' => $this->payload($identity->fresh(['attempts', 'approvedAttempt']))], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, ChildIdentityRequest $identity): JsonResponse
    {
        $this->authorizeOwner($request, $identity);

        return response()->json(['data' => $this->payload($identity->load(['attempts', 'approvedAttempt']))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function generate(Request $request, ChildIdentityRequest $identity, ChildIdentityAttemptService $attempts): JsonResponse
    {
        $this->authorizeOwner($request, $identity);
        abort_if(in_array($identity->status, ['converted', 'cancelled'], true), 422);
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $attempt = $attempts->create($identity, $validated['idempotency_key'], 'customer', $request->user());

        return response()->json(['data' => $this->attemptPayload($identity, $attempt)], 202);
    }

    public function approve(Request $request, ChildIdentityRequest $identity, ChildIdentityGenerationAttempt $attempt, ChildIdentityApprovalService $approvals): JsonResponse
    {
        $this->authorizeOwner($request, $identity);
        $identity = $approvals->approve($identity, $attempt, $request->user(), 'mobile');

        return response()->json(['data' => $this->payload($identity->load(['attempts', 'approvedAttempt']))]);
    }

    public function media(Request $request, ChildIdentityRequest $identity, ChildIdentityGenerationAttempt $attempt)
    {
        $this->authorizeOwner($request, $identity);
        abort_unless($attempt->child_identity_request_id === $identity->id && $attempt->status === 'succeeded' && $attempt->output_storage_path, 404);
        $disk = $attempt->output_disk ?: 'local';
        abort_if(str_contains($attempt->output_storage_path, '..') || ! Storage::disk($disk)->exists($attempt->output_storage_path), 404);

        return Storage::disk($disk)->response($attempt->output_storage_path, null, [
            'Content-Type' => data_get($attempt->response_metadata, 'output_mime_type', 'image/png'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function destroy(Request $request, ChildIdentityRequest $identity, MobileChildIdentityDeletionService $deletions): JsonResponse
    {
        $this->authorizeOwner($request, $identity);
        $deletions->delete($identity, $request->user());

        return response()->json(status: 204);
    }

    private function authorizeOwner(Request $request, ChildIdentityRequest $identity): void
    {
        abort_unless($identity->user_id === $request->user()->id, 404);
    }

    private function payload(ChildIdentityRequest $identity): array
    {
        $attempts = $identity->relationLoaded('attempts') ? $identity->attempts : $identity->attempts()->get();

        return [
            'id' => $identity->uuid,
            'child_profile_id' => $identity->childProfile?->uuid,
            'child_name' => $identity->child_name,
            'age_range' => $identity->age_range,
            'identity_type' => $identity->identity_type,
            'theme' => $identity->identity_theme,
            'status' => $identity->status,
            'approved_attempt_id' => $identity->approved_attempt_id,
            'attempts' => $attempts->sortByDesc('attempt_number')->values()->map(fn (ChildIdentityGenerationAttempt $attempt): array => $this->attemptPayload($identity, $attempt)),
            'can_retry' => ! in_array($identity->status, ['converted', 'cancelled'], true),
            'updated_at' => $identity->updated_at?->toISOString(),
        ];
    }

    private function attemptPayload(ChildIdentityRequest $identity, ChildIdentityGenerationAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'number' => $attempt->attempt_number,
            'status' => $attempt->status,
            'message' => $attempt->safe_error_message,
            'media_url' => $attempt->status === 'succeeded' ? route('api.v1.identities.attempts.media', [$identity->uuid, $attempt->id]) : null,
            'created_at' => $attempt->created_at?->toISOString(),
            'completed_at' => $attempt->completed_at?->toISOString(),
        ];
    }
}
