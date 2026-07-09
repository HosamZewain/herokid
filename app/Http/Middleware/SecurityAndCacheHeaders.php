<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityAndCacheHeaders
{
    private const PUBLIC_CACHE = 'public, max-age=300, s-maxage=86400, stale-while-revalidate=604800';

    private const PUBLIC_REVALIDATE_CACHE = 'public, max-age=0, s-maxage=0, no-cache, must-revalidate';

    private const PRIVATE_CACHE = 'no-store, no-cache, must-revalidate, private';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        $this->applySecurityHeaders($response, $request);
        $this->applyCacheHeaders($response, $request);

        return $response;
    }

    private function applySecurityHeaders(Response $response, Request $request): void
    {
        if ($request->isSecure() || strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; img-src 'self' https: data:; script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; font-src 'self' https: data:; connect-src 'self' https:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; upgrade-insecure-requests"
        );
    }

    private function applyCacheHeaders(Response $response, Request $request): void
    {
        if ($this->isPrivateCachedAdminAssetRoute($request) && $response->headers->has('Cache-Control')) {
            $response->headers->remove('Pragma');
            $response->headers->remove('Expires');

            return;
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $this->isPrivateRoute($request)) {
            $response->headers->set('Cache-Control', self::PRIVATE_CACHE);
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');

            return;
        }

        if ($request->routeIs('home') && ! $this->hasSessionCookie($request)) {
            $response->headers->set('Cache-Control', self::PUBLIC_REVALIDATE_CACHE);
            $response->headers->remove('Pragma');
            $response->headers->remove('Expires');
            $this->varyByCookie($response);

            return;
        }

        if ($this->isPublicCacheableRoute($request) && ! $this->hasSessionCookie($request)) {
            $response->headers->set('Cache-Control', self::PUBLIC_CACHE);
            $response->headers->remove('Pragma');
            $response->headers->remove('Expires');
            $this->varyByCookie($response);

            return;
        }

        if ($this->isPublicRoute($request)) {
            $response->headers->set('Cache-Control', 'private, no-cache');
        }
    }

    private function isPrivateCachedAdminAssetRoute(Request $request): bool
    {
        return $request->routeIs([
            'admin.production-studio.photo',
            'admin.production-studio.assets.show',
        ]);
    }

    private function isPrivateRoute(Request $request): bool
    {
        return $request->routeIs([
            'admin.*',
            'cart.*',
            'dashboard',
            'profile.*',
            'checkout.*',
            'orders.*',
            'track.*',
            'login',
            'register',
            'password.*',
            'verification.*',
        ]) || $request->is([
            'admin',
            'admin/*',
            'cart',
            'cart/*',
            'dashboard',
            'profile',
            'profile/*',
            'checkout',
            'checkout/*',
            'orders',
            'orders/*',
            'track-order',
            'login',
            'register',
            'forgot-password',
            'reset-password',
            'reset-password/*',
            'verify-email',
            'verify-email/*',
            'confirm-password',
            'password',
            'email/*',
        ]);
    }

    private function isPublicCacheableRoute(Request $request): bool
    {
        return $request->routeIs([
            'home',
            'stories.index',
            'stories.show',
            'faq',
            'pricing',
            'how-it-works',
            'contact',
            'privacy',
            'terms',
            'sitemap',
        ]);
    }

    private function isPublicRoute(Request $request): bool
    {
        return $request->routeIs([
            'home',
            'stories.index',
            'stories.show',
            'faq',
            'pricing',
            'how-it-works',
            'contact',
            'privacy',
            'terms',
            'sitemap',
        ]);
    }

    private function hasSessionCookie(Request $request): bool
    {
        return $request->cookies->has((string) config('session.cookie')) || $request->cookies->has('XSRF-TOKEN');
    }

    private function varyByCookie(Response $response): void
    {
        $vary = $response->headers->all('Vary');
        $values = [];

        foreach ($vary as $header) {
            foreach (explode(',', $header) as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $values[strtolower($value)] = $value;
                }
            }
        }

        $values['cookie'] = 'Cookie';

        $response->headers->set('Vary', implode(', ', array_values($values)));
    }
}
