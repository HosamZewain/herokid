<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AppLandingRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppLandingMetricsController extends Controller
{
    public function visit(Request $request, AppLandingRecorder $recorder)
    {
        $request->validate(['v' => ['required', 'uuid']]);
        abort_unless($request->header('X-HeroKid-App-Metric') === '1', 400);
        try {
            $recorder->record($request);
        } catch (\Throwable $exception) {
            Log::warning('App landing visit recording failed.', ['exception_class' => $exception::class]);

            return response()->noContent(503)->header('Cache-Control', 'private, no-store');
        }

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, string $platform, AppLandingRecorder $recorder)
    {
        abort_unless(in_array($platform, ['ios', 'android'], true), 404);
        try {
            $recorder->record($request, $platform);
        } catch (\Throwable $exception) {
            // An analytics outage must never prevent reaching the real store.
            Log::warning('App landing download recording failed.', ['exception_class' => $exception::class]);
        }

        return redirect()->away(config('mobile_app.'.$platform.'_url'))
            ->header('Cache-Control', 'private, no-store');
    }
}
