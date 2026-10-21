<?php

namespace App\Http\Middleware;

use App\Services\RemoteStudio\StudioSessionService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureStudioAccess
{
    public function handle(Request $request, Closure $next, ?string $permission = null): mixed
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && in_array(StudioSessionService::ABILITY, $token->abilities ?? [], true), 403, 'An employee Studio session is required.');
        app(StudioSessionService::class)->validate($user, $token);
        abort_if($permission && ! $user->hasPermission($permission), 403);

        return $next($request);
    }
}
