<?php

namespace App\Http\Middleware;

use App\Services\Cart\CartTrackingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackCustomerVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        app(CartTrackingService::class)->captureAttribution($request);

        $response = $next($request);

        $user = $request->user();

        if ($user && $user->role !== 'admin') {
            $lastSeen = $user->last_seen_at;

            if (! $lastSeen || $lastSeen->lt(now()->subMinutes(5))) {
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        }

        return $response;
    }
}
