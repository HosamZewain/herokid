<?php

namespace App\Http\Middleware;

use App\Services\RemoteStudio\StudioSessionService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class StudioSessionBoundary
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->bearerToken()) {
            $user = auth('sanctum')->user();
            $token = $user?->currentAccessToken();
            if ($token instanceof PersonalAccessToken && in_array(StudioSessionService::ABILITY, $token->abilities ?? [], true)) {
                abort_unless($request->is('api/studio/*', 'api/agent/*'), 403, 'Studio sessions cannot access this API.');
                app(StudioSessionService::class)->validate($user, $token);
            }
        }

        return $next($request);
    }
}
