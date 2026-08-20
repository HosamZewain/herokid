<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrivacyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrivacyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->privacyRequests()->latest('requested_at')->get()->map(fn (PrivacyRequest $item): array => $this->payload($item))])->header('Cache-Control', 'private, no-store');
    }

    public function consents(Request $request): JsonResponse
    {
        $records = DB::table('consent_records')->where('user_id', $request->user()->id)->latest('recorded_at')->get();

        return response()->json(['data' => $records->map(fn ($record): array => [
            'id' => $record->id,
            'child_profile_id' => $record->child_profile_id,
            'type' => $record->consent_type,
            'document_version' => $record->document_version,
            'granted' => (bool) $record->granted,
            'recorded_at' => $record->recorded_at,
            'withdrawn_at' => $record->withdrawn_at,
            'source' => $record->source,
        ])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['account_deletion', 'order_media_deletion', 'data_export'])],
            'subject_uuid' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'password' => ['required_if:request_type,account_deletion', 'nullable', 'string'],
            'confirmation' => ['required_if:request_type,account_deletion', 'nullable', 'string'],
        ]);
        if ($data['request_type'] === 'account_deletion') {
            if (! Hash::check((string) ($data['password'] ?? ''), $request->user()->password) || ($data['confirmation'] ?? null) !== 'DELETE_MY_ACCOUNT') {
                throw ValidationException::withMessages(['confirmation' => 'Password and account-deletion confirmation are required.']);
            }
        }

        $privacy = DB::transaction(function () use ($request, $data): PrivacyRequest {
            $existing = $request->user()->privacyRequests()->where('request_type', $data['request_type'])->whereIn('status', ['pending', 'processing'])->first();
            if ($existing) {
                return $existing;
            }
            $privacy = $request->user()->privacyRequests()->create([
                'request_type' => $data['request_type'],
                'subject_type' => $data['request_type'] === 'order_media_deletion' ? 'order' : null,
                'subject_uuid' => $data['subject_uuid'] ?? null,
                'status' => 'pending',
                'reason' => $data['reason'] ?? null,
                'scope' => $this->scope($data['request_type']),
                'requested_at' => now(),
                'due_at' => now()->addDays(30),
            ]);
            if ($data['request_type'] === 'account_deletion') {
                $request->user()->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(30)])->save();
            }

            return $privacy;
        });

        return response()->json(['data' => $this->payload($privacy)], $privacy->wasRecentlyCreated ? 201 : 200);
    }

    public function cancel(Request $request, string $privacyRequest): JsonResponse
    {
        $privacy = PrivacyRequest::query()->where('uuid', $privacyRequest)->where('user_id', $request->user()->id)->where('status', 'pending')->firstOrFail();
        $privacy->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        if ($privacy->request_type === 'account_deletion') {
            $request->user()->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->save();
        }

        return response()->json(['data' => $this->payload($privacy->fresh())]);
    }

    private function scope(string $type): array
    {
        return match ($type) {
            'account_deletion' => ['account', 'child_profiles', 'reusable_child_media', 'sessions', 'devices'],
            'order_media_deletion' => ['retained_order_media_subject_to_legal_and_fulfillment_requirements'],
            default => ['account', 'orders', 'child_profiles', 'consents'],
        };
    }

    private function payload(PrivacyRequest $request): array
    {
        return [
            'id' => $request->uuid,
            'type' => $request->request_type,
            'status' => $request->status,
            'subject_uuid' => $request->subject_uuid,
            'scope' => $request->scope,
            'requested_at' => $request->requested_at?->toISOString(),
            'due_at' => $request->due_at?->toISOString(),
            'cancelled_at' => $request->cancelled_at?->toISOString(),
            'completed_at' => $request->completed_at?->toISOString(),
        ];
    }
}
