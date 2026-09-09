<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Resources\Agent\AgentStudioOrderResource;
use App\Services\AgentApi\AgentStudioOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentStudioController extends Controller
{
    public function connection(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'success' => true,
            'agent' => [
                'id' => $request->user()->getKey(),
                'name' => $request->user()->name,
            ],
            'abilities' => array_values($token->abilities ?? []),
            'studio_api' => true,
            'api_version' => '1',
        ]);
    }

    public function show(Request $request, string $orderNumber, AgentStudioOrderService $orders): JsonResponse
    {
        return response()->json(
            (new AgentStudioOrderResource($orders->find($orderNumber)))->resolve($request),
        );
    }
}
