<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mobile\MobileOtpService;
use App\Services\Mobile\MobileTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MobileOtpController extends Controller
{
    public function request(Request $request, MobileOtpService $otp): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        return response()->json(['data' => $otp->create($data['phone'], $request)], 202);
    }

    public function verify(Request $request, MobileOtpService $otp, MobileTokenIssuer $tokens): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
            'name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = DB::transaction(function () use ($data, $otp): User {
            $phone = $otp->consume($data['challenge_id'], $data['code']);
            $user = User::query()->where('phone', $phone)->first();
            if (! $user) {
                $user = User::query()->create([
                    'name' => trim((string) ($data['name'] ?? 'ولي أمر HeroKid')) ?: 'ولي أمر HeroKid',
                    'email' => null,
                    'phone' => $phone,
                    'password' => Hash::make(Str::random(64)),
                    'last_seen_at' => now(),
                ]);
            } else {
                abort_unless($user->is_active, 403, 'This account is not active.');
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            }

            return $user;
        });

        return response()->json($tokens->issue($user, $data['device_name']));
    }
}
