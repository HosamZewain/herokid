<?php

namespace App\Http\Middleware;

use App\Services\RoboDesk\RoboDeskCredentialService;
use App\Services\RoboDesk\RoboDeskSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates inbound RoboDesk calls with a static token in a configurable
 * header. A missing or unconfigured token is always a rejection, never a pass.
 */
class VerifyRoboDeskSignature
{
    public function __construct(
        private readonly RoboDeskSettings $settings,
        private readonly RoboDeskCredentialService $credentials,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->enabled(), 503, 'RoboDesk integration is disabled.');
        abort_unless($this->tokenMatches($request), 401, 'RoboDesk request could not be authenticated.');

        return $next($request);
    }

    private function tokenMatches(Request $request): bool
    {
        $expected = $this->credentials->value('inbound_token');

        if ($expected === '') {
            return false;
        }

        $presented = trim((string) $request->header($this->settings->inboundAuthHeader(), ''));

        if ($presented === '') {
            return false;
        }

        // Tolerate a scheme prefix ("Bearer abc") so the same header works
        // whether or not RoboDesk sends one.
        $parts = explode(' ', $presented);

        return hash_equals($expected, trim(end($parts)));
    }
}
