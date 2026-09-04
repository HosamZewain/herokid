<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bosta\BostaWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BostaWebhookController extends Controller
{
    public function __invoke(Request $request, BostaWebhookService $webhooks): JsonResponse
    {
        abort_unless(config('bosta.enabled'), 503, 'Bosta integration is disabled.');
        $header = (string) config('bosta.webhook_header');
        $expected = (string) config('bosta.webhook_secret');
        abort_unless($expected !== '' && hash_equals($expected, (string) $request->header($header)), 401, 'Invalid Bosta webhook credentials.');

        $payload = $request->validate([
            '_id' => ['nullable', 'string', 'max:191'],
            'trackingNumber' => ['nullable', 'string', 'max:191'],
            'businessReference' => ['nullable', 'string', 'max:191'],
            'state' => ['required', 'integer'],
            'timeStamp' => ['nullable'],
            'cod' => ['nullable', 'numeric', 'min:0'],
            'type' => ['nullable'],
            'isConfirmedDelivery' => ['nullable', 'boolean'],
            'deliveryPromiseDate' => ['nullable', 'date'],
            'exceptionReason' => ['nullable', 'string', 'max:2000'],
            'exceptionCode' => ['nullable', 'string', 'max:191'],
            'numberOfAttempts' => ['nullable', 'integer', 'min:0'],
        ]);
        abort_if(blank($payload['_id'] ?? null) && blank($payload['trackingNumber'] ?? null) && blank($payload['businessReference'] ?? null), 422, 'Shipment identifier is required.');

        $shipment = $webhooks->handle($payload);

        return response()->json(['success' => true, 'shipment_id' => $shipment->id]);
    }
}
