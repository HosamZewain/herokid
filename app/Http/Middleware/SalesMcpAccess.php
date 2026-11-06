<?php

namespace App\Http\Middleware;

use App\Services\SalesAssistant\SalesCapabilities;
use App\Services\SalesAssistant\SalesSettings;
use Closure;
use Illuminate\Http\Request;

class SalesMcpAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(SalesSettings::class)->enabled(), 404);
        $request->attributes->set('sales_turn', app(SalesCapabilities::class)->resolve($request->bearerToken() ?? ''));
        $response = $next($request);

        return $response->header('Cache-Control', 'private, no-store');
    }
}
