<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RemoteStudio\StudioSessionService;
use App\Support\AdminActivityLogger;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class StudioAuthController extends Controller
{
    public function login(Request $request, StudioSessionService $sessions): mixed
    {
        $data = $request->validate(['login' => 'required|string|max:254', 'password' => 'required|string|max:1024']);
        $identifier = trim($data['login']);
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $value = $field === 'phone' ? Phone::normalize($identifier) : mb_strtolower($identifier);
        $key = 'studio-login:'.hash('sha256', $value.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many sign-in attempts. Retry later.'], 429, ['Retry-After' => RateLimiter::availableIn($key), 'Cache-Control' => 'no-store']);
        }
        RateLimiter::hit($key, 300);
        $user = filled($value) ? User::where($field, $value)->first() : null;
        // A fixed dummy hash prevents obvious unknown-account timing disclosure.
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (! $valid || ! $user?->hasPermission('studio.access')) {
            Log::notice('Studio sign-in rejected.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid credentials or Studio access is not permitted.'], 401, ['Cache-Control' => 'no-store']);
        }
        RateLimiter::clear($key);

        return response()->json(['data' => $sessions->issue($user)])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request, StudioSessionService $sessions): mixed
    {
        return response()->json(['data' => $sessions->identity($request->user(), $request->user()->currentAccessToken())])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request, StudioSessionService $sessions): mixed
    {
        $session = $sessions->validate($request->user(), $request->user()->currentAccessToken());
        $sessions->revoke($session);
        AdminActivityLogger::log('studio.logout', properties: ['session_id' => $session->id]);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
