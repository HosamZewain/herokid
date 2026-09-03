<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureAgentApiAccess
{
    public function handle(Request $request, Closure $next, string ...$requirements): mixed
    {
        $user = $request->user();

        if (! $user || ! $user->currentAccessToken() instanceof PersonalAccessToken) {
            return $this->error('UNAUTHORIZED', 'Agent authentication is required.', 401);
        }

        if (! $user->isAdmin() || ! $user->agent_api_enabled || ! $user->tokenCan('agent')) {
            return $this->error('FORBIDDEN', 'This account is not enabled for Agent API access.', 403);
        }

        foreach ($requirements as $requirement) {
            [$kind, $value] = array_pad(explode(':', $requirement, 2), 2, null);

            if ($kind === 'ability' && (! $value || ! $user->tokenCan('agent:'.$value))) {
                return $this->error('FORBIDDEN', 'The Agent token does not have the required ability.', 403);
            }

            if ($kind === 'permission' && (! $value || ! $user->hasPermission($value))) {
                return $this->error('FORBIDDEN', 'The Agent account does not have the required permission.', 403);
            }
        }

        return $next($request);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}
