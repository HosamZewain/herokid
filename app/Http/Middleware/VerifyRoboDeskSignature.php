<?php

namespace App\Http\Middleware;

use App\Services\RoboDesk\RoboDeskIntegrationRegistry;
use App\Services\RoboDesk\RoboDeskSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates inbound RoboDesk calls.
 *
 * Each integration carries one static token used in both directions, so a
 * callback is accepted when it presents the token of any enabled integration.
 * A missing token, or none configured, is always a rejection.
 */
class VerifyRoboDeskSignature
{
    public function __construct(
        private readonly RoboDeskSettings $settings,
        private readonly RoboDeskIntegrationRegistry $integrations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->enabled(), 503, 'RoboDesk integration is disabled.');
        abort_unless($this->tokenMatches($request), 401, 'RoboDesk request could not be authenticated.');

        return $next($request);
    }

    private function tokenMatches(Request $request): bool
    {
        $accepted = $this->integrations->inboundTokens();

        if ($accepted === []) {
            return false;
        }

        $presented = $this->presentedToken($request);

        if ($presented === '') {
            return false;
        }

        foreach ($accepted as $token) {
            if (hash_equals($token, $presented)) {
                return true;
            }
        }

        return false;
    }

    /** Read from the configured header, falling back to Authorization. */
    private function presentedToken(Request $request): string
    {
        $value = trim((string) $request->header($this->settings->inboundAuthHeader(), ''));

        if ($value === '') {
            $value = trim((string) $request->header('Authorization', ''));
        }

        if ($value === '') {
            return '';
        }

        // Tolerate a scheme prefix ("Bearer abc") so the same header works
        // whether or not RoboDesk sends one.
        $parts = explode(' ', $value);

        return trim(end($parts));
    }
}
