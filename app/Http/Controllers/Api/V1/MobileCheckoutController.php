<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileAnalyticsRecorder;
use App\Services\Mobile\MobileCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobileCheckoutController extends Controller
{
    public function store(Request $request, MobileCheckoutService $checkout, MobileAnalyticsRecorder $analytics): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::in(['cash_on_delivery', 'card', 'mobile_wallet'])],
            'terms_accepted' => ['accepted'],
            'terms_document_version' => ['required', 'string', 'max:40'],
            'image_processing_consent' => ['accepted'],
            'consent_document_version' => ['required', 'string', 'max:40'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $analytics->record($request, 'checkout_started', ['payment_method' => $data['payment_method']]);
        $payload = $checkout->checkout($request->user(), $data);
        if ($payload['status'] === 'completed') {
            $analytics->record($request, 'order_completed', ['checkout_id' => $payload['checkout_id'], 'order_count' => count($payload['orders'])]);
        }
        $status = $payload['status'] === 'completed' ? 201 : 202;

        return response()->json(['data' => $payload], $status)->header('Cache-Control', 'private, no-store');
    }
}
