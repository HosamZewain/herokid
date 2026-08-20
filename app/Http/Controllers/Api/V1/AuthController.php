<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mobile\MobileTokenIssuer;
use App\Support\Phone;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, MobileTokenIssuer $tokens): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $phone = Phone::normalize($request->input('phone'));
        $request->merge(['email' => $email, 'phone' => $phone]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique(User::class)],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        [$user, $payload] = DB::transaction(function () use ($tokens, $validated): array {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?: null,
                'password' => $validated['password'],
                'last_seen_at' => now(),
            ]);

            return [$user, $tokens->issue($user, $validated['device_name'])];
        });

        event(new Registered($user));

        return response()->json($payload, 201);
    }

    public function login(Request $request, MobileTokenIssuer $tokens): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $login = trim($validated['login']);
        $isEmail = filter_var($login, FILTER_VALIDATE_EMAIL) !== false;
        $normalized = $isEmail ? mb_strtolower($login) : Phone::normalize($login);
        $user = User::query()->where($isEmail ? 'email' : 'phone', $normalized)->first();

        if (! $user || ! Hash::check($validated['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['login' => [__('auth.failed')]]);
        }

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json($tokens->issue($user, $validated['device_name']));
    }

    public function me(Request $request, MobileTokenIssuer $tokens): JsonResponse
    {
        return response()->json(['data' => ['user' => $tokens->user($request->user())]]);
    }

    public function update(Request $request, MobileTokenIssuer $tokens): JsonResponse
    {
        $user = $request->user();
        $email = mb_strtolower(trim((string) $request->input('email')));
        $phone = Phone::normalize($request->input('phone'));
        $request->merge(['email' => $email, 'phone' => $phone]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique(User::class)->ignore($user->id)],
        ]);
        $emailChanged = $user->email !== $validated['email'];
        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        return response()->json(['data' => ['user' => $tokens->user($user->fresh())]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'All mobile sessions were revoked.']);
    }
}
