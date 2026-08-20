<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\RoboDesk\PaymentProofService;
use App\Services\RoboDesk\RoboDeskCheckoutPayload;
use App\Services\RoboDesk\RoboDeskInboundEventHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoboDeskIntegrationController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ready',
            'integration' => 'herokid-robodesk',
            'version' => 'v1',
            'whatsapp_number' => config('robodesk.whatsapp_number'),
        ]);
    }

    public function checkout(string $checkoutReference, RoboDeskCheckoutPayload $payload): JsonResponse
    {
        return response()->json(['data' => $payload->build($checkoutReference)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function event(Request $request, RoboDeskInboundEventHandler $handler): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'type' => ['required', Rule::in([
                'order.confirmed', 'order.rejected',
                'identity.approved', 'identity.changes_requested',
                'preview.approved', 'preview.changes_requested',
            ])],
            'occurred_at' => ['nullable', 'date'],
            'data' => ['required', 'array'],
        ]);

        abort_unless(hash_equals((string) $request->header('X-RoboDesk-Event-Id'), (string) $data['id']), 422, 'Event id mismatch.');

        $event = RoboDeskIntegrationEvent::query()->firstOrCreate(['event_id' => $data['id']], [
            'direction' => 'inbound',
            'event_type' => $data['type'],
            'aggregate_type' => 'checkout',
            'aggregate_id' => data_get($data, 'data.checkout_reference'),
            'checkout_group_key' => data_get($data, 'data.checkout_reference'),
            'status' => 'processing',
            'attempts' => 1,
            'payload' => $data['data'],
        ]);

        if (! $event->wasRecentlyCreated && $event->status !== 'failed') {
            return response()->json(['accepted' => true, 'duplicate' => true]);
        }

        if (! $event->wasRecentlyCreated) {
            $event->increment('attempts');
            $event->forceFill([
                'status' => 'processing',
                'last_error' => null,
                'payload' => $data['data'],
            ])->save();
        }

        try {
            $handler->handle($data['type'], $data['data']);
            $event->update(['status' => 'succeeded', 'processed_at' => now()]);
        } catch (\Throwable $exception) {
            $event->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }

        return response()->json(['accepted' => true], 202);
    }

    public function paymentProof(Request $request, PaymentProofService $proofs): JsonResponse
    {
        $validated = $request->validate([
            'checkout_reference' => ['required', 'string', 'max:255'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(max(1, (int) config('robodesk.payment_proof_max_mb')) * 1024)],
            'message_id' => ['required', 'string', 'max:255'],
            'conversation_id' => ['nullable', 'string', 'max:255'],
            'sender_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $proof = $proofs->store($validated['checkout_reference'], $request->file('proof'), $validated);

        return response()->json(['accepted' => true, 'proof_id' => $proof->uuid, 'status' => $proof->status], 202);
    }
}
