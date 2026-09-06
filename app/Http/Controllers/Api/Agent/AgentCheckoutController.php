<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Services\AgentApi\AgentCheckoutProductionService;
use App\Services\AgentApi\AgentIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentCheckoutController extends Controller
{
    public function acquireNext(
        Request $request,
        AgentCheckoutProductionService $production,
        AgentIdempotencyService $idempotency,
    ): JsonResponse {
        $result = $idempotency->execute($request->user(), 'checkouts.acquire-next', $request, function () use ($request, $production): array {
            $checkout = $production->acquireNext($request->user(), $request);
            $body = $checkout
                ? ['success' => true, 'checkout' => $checkout]
                : [
                    'success' => true,
                    'checkout' => null,
                    'reason' => 'NO_AVAILABLE_ORDERS',
                    'queue' => $production->queueDiagnostics($request->user()),
                ];

            return [
                'status' => 200,
                'body' => $body,
                'checkout_group_key' => $checkout['checkout_group'] ?? null,
                'cache' => $checkout !== null,
            ];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function acquireNextRevision(
        Request $request,
        AgentCheckoutProductionService $production,
        AgentIdempotencyService $idempotency,
    ): JsonResponse {
        $result = $idempotency->execute($request->user(), 'checkouts.acquire-next-revision', $request, function () use ($request, $production): array {
            $checkout = $production->acquireNextRevision($request->user(), $request);
            $body = $checkout
                ? ['success' => true, 'checkout' => $checkout]
                : ['success' => true, 'checkout' => null, 'reason' => 'NO_AVAILABLE_REVISIONS'];

            return [
                'status' => 200,
                'body' => $body,
                'checkout_group_key' => $checkout['checkout_group'] ?? null,
                'cache' => $checkout !== null,
            ];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function context(Request $request, string $reference, AgentCheckoutProductionService $production): JsonResponse
    {
        return response()->json($production->context($reference, $request->user()));
    }

    public function acquire(
        Request $request,
        string $reference,
        AgentCheckoutProductionService $production,
        AgentIdempotencyService $idempotency,
    ): JsonResponse {
        $result = $idempotency->execute($request->user(), 'checkouts.acquire-specific:'.$reference, $request, function () use ($request, $reference, $production): array {
            $body = $production->acquireSpecific($reference, $request->user(), $request);

            return [
                'status' => 200,
                'body' => $body,
                'checkout_group_key' => data_get($body, 'checkout.checkout_group'),
            ];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function complete(
        Request $request,
        string $reference,
        AgentCheckoutProductionService $production,
        AgentIdempotencyService $idempotency,
    ): JsonResponse {
        $result = $idempotency->execute($request->user(), 'checkouts.complete-production:'.$reference, $request, function () use ($request, $reference, $production): array {
            $body = $production->complete($reference, $request->user(), $request);

            return ['status' => 200, 'body' => $body, 'checkout_group_key' => $body['checkout_group_key'] ?? null];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function startRework(
        Request $request,
        string $reference,
        AgentCheckoutProductionService $production,
        AgentIdempotencyService $idempotency,
    ): JsonResponse {
        $result = $idempotency->execute($request->user(), 'checkouts.start-rework:'.$reference, $request, function () use ($request, $reference, $production): array {
            $body = $production->startRework($reference, $request->user(), $request);

            return [
                'status' => 200,
                'body' => $body,
                'checkout_group_key' => $body['checkout_group_key'] ?? null,
            ];
        });

        return response()->json($result['body'], $result['status']);
    }
}
