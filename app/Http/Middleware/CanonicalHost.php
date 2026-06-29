<?php

namespace App\Http\Middleware;

use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production')) {
            return $next($request);
        }

        $canonicalHost = parse_url(Seo::canonicalBase(), PHP_URL_HOST);
        $currentHost = strtolower($request->getHost());
        $forwardedProto = strtolower((string) $request->headers->get('X-Forwarded-Proto'));
        $isHttp = (! $request->isSecure() && $forwardedProto !== 'https') || $forwardedProto === 'http';

        if ($canonicalHost && ($currentHost !== $canonicalHost || $isHttp)) {
            $target = Seo::url($request->getRequestUri() ?: '/');

            return redirect()->away($target, 301);
        }

        return $next($request);
    }
}
