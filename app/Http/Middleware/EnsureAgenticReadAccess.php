<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureAgenticReadAccess
{
    public static function allowed(?User $user, bool $finance = false): bool
    {
        $token = $user?->currentAccessToken();

        return config('agentic.mcp_enabled') && $user?->isAdmin() && $user->is_active && $user->agent_api_enabled
            && $token instanceof PersonalAccessToken && ! $token->expires_at?->isPast()
            && in_array('agentic:read', $token->abilities, true)
            && $user->hasPermission('orders.view') && $user->hasPermission('order_automation.view')
            && (! $finance || (in_array('agentic:finance', $token->abilities, true) && $user->hasPermission('orders.statistics.view')));
    }

    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('agentic.mcp_enabled'), 404);
        abort_unless(self::allowed($request->user()), 403);
        $origin = $request->header('Origin');
        abort_if($origin && rtrim($origin, '/') !== rtrim(config('app.url'), '/'), 403);

        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
