<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mobile\MobileTokenIssuer;
use App\Support\Phone;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $request->validate([
            'login' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        // The website and mobile app share one account: one identifier is enough.
        // Retain email/phone payloads for older mobile clients during rollout.
        if ($request->has('login')) {
            $identifier = trim((string) $request->input('login'));
            $isEmail = str_contains($identifier, '@');
            $request->merge(['email' => $isEmail ? $identifier : null, 'phone' => $isEmail ? null : $identifier]);
        }
        $email = mb_strtolower(trim((string) $request->input('email'))) ?: null;
        $phone = $this->accountPhone($request->input('phone'));
        $request->merge(['email' => $email, 'phone' => $phone]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required_without:phone', 'nullable', 'email:rfc', 'max:255', Rule::unique(User::class)],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32', $this->phoneRule(), Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        if ($phone && User::query()->whereIn('phone', Phone::equivalentValues($phone))->exists()) {
            throw ValidationException::withMessages(['phone' => [__('validation.unique', ['attribute' => 'phone'])]]);
        }

        try {
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
        } catch (UniqueConstraintViolationException) {
            // Concurrent registrations of equivalent Egyptian formats share a unique value.
            $field = $email && User::query()->where('email', $email)->exists() ? 'email' : 'phone';
            throw ValidationException::withMessages([$field => [__('validation.unique', ['attribute' => $field])]]);
        }

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
        $matches = $isEmail
            ? User::query()->where('email', $normalized)->limit(2)->get()
            : (Phone::isValidMobile($login) ? User::query()->whereIn('phone', Phone::equivalentValues($normalized))->limit(2)->get() : collect());
        // Never guess which account owns a historical duplicate phone number.
        $user = $matches->count() === 1 ? $matches->first() : null;

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
        $request->validate([
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        $user = $request->user();
        $email = mb_strtolower(trim((string) $request->input('email'))) ?: null;
        $phone = $this->accountPhone($request->input('phone'));
        $request->merge(['email' => $email, 'phone' => $phone]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required_without:phone', 'nullable', 'email:rfc', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32', $this->phoneRule(), Rule::unique(User::class)->ignore($user->id)],
        ]);
        if ($phone && User::query()->whereKeyNot($user->id)->whereIn('phone', Phone::equivalentValues($phone))->exists()) {
            throw ValidationException::withMessages(['phone' => [__('validation.unique', ['attribute' => 'phone'])]]);
        }
        $emailChanged = $user->email !== $validated['email'];
        $phoneChanged = $user->phone !== ($validated['phone'] ?: null);
        try {
            $user->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?: null,
                'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
                'phone_verified_at' => $phoneChanged ? null : $user->phone_verified_at,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            $field = $email && User::query()->whereKeyNot($user->id)->where('email', $email)->exists() ? 'email' : 'phone';
            throw ValidationException::withMessages([$field => [__('validation.unique', ['attribute' => $field])]]);
        }

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

    private function accountPhone(?string $phone): ?string
    {
        $normalized = Phone::normalize($phone);
        if ($normalized && preg_match('/^\+?201[0125]\d{8}$/', $normalized)) {
            return '0'.substr(ltrim($normalized, '+'), 2);
        }

        return $normalized;
    }

    private function phoneRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! Phone::isValidMobile($value)) {
                $fail('Enter a valid mobile number.');
            }
        };
    }
}
