<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MobileSocialIdentityVerifier;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Mobile\MobileTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    public function __invoke(
        Request $request,
        MobileSocialIdentityVerifier $verifier,
        MobileTokenIssuer $tokens
    ): JsonResponse {
        $data = $request->validate([
            'provider' => ['required', 'in:google,apple'],
            'id_token' => ['required', 'string', 'max:10000'],
            'name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);
        $identity = $verifier->verify($data['provider'], $data['id_token']);

        $user = DB::transaction(function () use ($data, $identity): User {
            $account = SocialAccount::query()
                ->where('provider', $data['provider'])
                ->where('provider_subject', $identity['subject'])
                ->lockForUpdate()
                ->first();
            if ($account) {
                $user = $account->user;
            } else {
                $email = $identity['email_verified'] ? $identity['email'] : null;
                $user = $email ? User::query()->where('email', $email)->first() : null;
                $user ??= User::query()->create([
                    'name' => trim((string) ($identity['name'] ?? $data['name'] ?? 'ولي أمر HeroKid')) ?: 'ولي أمر HeroKid',
                    'email' => $email,
                    'email_verified_at' => $email ? now() : null,
                    'password' => Hash::make(Str::random(64)),
                    'last_seen_at' => now(),
                ]);

                SocialAccount::query()->create([
                    'user_id' => $user->id,
                    'provider' => $data['provider'],
                    'provider_subject' => $identity['subject'],
                    'email' => $email,
                ]);
            }

            abort_unless($user->is_active, 403, 'This account is not active.');
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();

            return $user;
        });

        return response()->json($tokens->issue($user, $data['device_name']));
    }
}
