<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AnonymousGameTraffic
{
    public function handle(Request $request, Closure $next): mixed
    {
        // These routes never resolve an authenticated user or consume a purchase session.
        foreach (['Authorization', 'Cookie', 'X-Device-Installation', 'X-Installation-Id'] as $header) {
            $request->headers->remove($header);
        }
        $request->cookies->replace([]);
        $request->setUserResolver(static fn () => null);
        if ($request->isMethod('POST')) {
            if ((int) $request->header('Content-Length', 0) > 65536 || strlen($request->getContent()) > 65536) {
                return response()->json(['error' => ['code' => 'payload_too_large']], 413);
            }
            if (! $request->isJson()) {
                return response()->json(['error' => ['code' => 'json_required']], 415);
            }
        }

        return $next($request);
    }
}
