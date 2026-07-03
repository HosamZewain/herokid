<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }

        if (! $user->hasAllPermissions($permissions)) {
            abort(403);
        }

        return $next($request);
    }
}
