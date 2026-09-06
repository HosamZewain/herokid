<?php

namespace App\Http\Middleware;

use App\Services\RoboDesk\RoboDeskCredentialService;
use App\Services\RoboDesk\RoboDeskSettings;
use App\Services\RoboDesk\RoboDeskSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates inbound RoboDesk calls.
 *
 * The agreed contract is token-only: RoboDesk sends the same static token back
 * in a configurable header. The original HMAC scheme is kept as an option in
 * case signing is adopted later, and `none` exists for local work.
 */
class VerifyRoboDeskSignature
{
    public function __construct(
        private readonly RoboDeskSignature $signatures,
        private readonly RoboDeskSettings $settings,
        private readonly RoboDeskCredentialService $credentials,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->enabled(), 503, 'RoboDesk integration is disabled.');

        abort_unless($this->authenticated($request), 401, 'RoboDesk request could not be authenticated.');

        return $next($request);
    }

    private function authenticated(Request $request): bool
    {
        return match ($this->settings->inboundAuthMode()) {
            'none' => true,
            'signature' => $this->signatures->valid(
                $request->getContent(),
                $request->header('X-RoboDesk-Timestamp'),
                $request->header('X-RoboDesk-Event-Id'),
                $request->header('X-RoboDesk-Signature'),
                $this->credentials->value('inbound_secret'),
            ),
            default => $this->tokenMatches($request),
        };
    }

    /**
     * A missing token is a rejection, never a pass — an unconfigured
     * integration must not accept unauthenticated traffic.
     */
    private function tokenMatches(Request $request): bool
    {
        $expected = $this->credentials->value('auth_token');

        if ($expected === '') {
            return false;
        }

        $presented = (string) $request->header($this->settings->inboundAuthHeader(), '');

        if ($presented === '') {
            return false;
        }

        // Tolerate a scheme prefix ("Bearer abc") so the same header can be
        // reused whether or not RoboDesk sends one.
        $parts = explode(' ', trim($presented));
        $presented = trim(end($parts));

        return hash_equals($expected, $presented);
    }
}
