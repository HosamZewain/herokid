<?php

namespace App\Http\Middleware;

use App\Services\RoboDesk\RoboDeskSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyRoboDeskSignature
{
    public function __construct(private readonly RoboDeskSignature $signatures) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('robodesk.enabled'), 503, 'RoboDesk integration is disabled.');

        $valid = $this->signatures->valid(
            $request->getContent(),
            $request->header('X-RoboDesk-Timestamp'),
            $request->header('X-RoboDesk-Event-Id'),
            $request->header('X-RoboDesk-Signature'),
            (string) config('robodesk.inbound_secret'),
        );

        abort_unless($valid, 401, 'Invalid RoboDesk signature.');

        return $next($request);
    }
}
