<?php

namespace App\Http\Middleware;

use App\Models\MobilePromoCode;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileGuestScope
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'mobile_guest') {
            return $next($request);
        }

        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('mobile:guest')
            && $token->expires_at?->isFuture(), 403);
        // Installation UUID is metadata, never an alternative to the secret
        // bearer token. A guest cannot reach accounts, private PDFs, identity
        // generation, consent administration, devices, campaigns or sessions.
        $relative = substr($request->path(), strlen('api/v1/'));
        $method = $request->method();
        $allowed = match (true) {
            $relative === 'guest-session' => in_array($method, ['GET', 'DELETE'], true),
            preg_match('~^children(?:/[0-9a-f-]{36}(?:/photos(?:/[0-9a-f-]{36}(?:/media)?)?)?)?$~i', $relative) === 1 => in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true),
            preg_match('~^uploads(?:/[0-9a-f-]{36}(?:/chunks/[0-9]+|/attach-child-photo)?)?$~i', $relative) === 1 => in_array($method, ['GET', 'POST', 'DELETE'], true),
            preg_match('~^addresses(?:/[0-9a-f-]{36})?$~i', $relative) === 1 => in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true),
            preg_match('~^cart(?:/items(?:/batch|/[0-9a-f-]{36}(?:/duplicate)?)?|/promo-code)?$~i', $relative) === 1 => in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true),
            in_array($relative, ['checkout', 'checkout/quote'], true) => $method === 'POST',
            preg_match('~^orders(?:/[1-9][0-9]*)?$~', $relative) === 1 => $method === 'GET',
            default => false,
        };
        abort_unless($allowed, 403, 'This action requires a customer account.');
        if ($relative === 'uploads' && $method === 'POST') {
            abort_unless($request->input('purpose') === 'child_reference', 403);
        }
        if ($relative === 'cart/promo-code' && $method === 'PUT' && is_string($request->input('code'))) {
            $promo = MobilePromoCode::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim((string) $request->input('code')))])->first();
            abort_if($promo && $promo->per_user_limit !== null, 422, 'This account-limited code requires sign-in. You can continue your guest order without it.');
        }

        return $next($request);
    }
}
