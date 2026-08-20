<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        return response()->json(['data' => $request->user()->tokens()->latest()->get()->map(fn ($token): array => [
            'id' => $token->id,
            'name' => $token->name,
            'current' => $token->id === $currentId,
            'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'created_at' => $token->created_at?->toISOString(),
        ])])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $session): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($session)->firstOrFail();
        $current = $request->user()->currentAccessToken()?->id === $token->id;
        $token->delete();

        return response()->json(['data' => ['revoked' => true, 'current' => $current]]);
    }
}
