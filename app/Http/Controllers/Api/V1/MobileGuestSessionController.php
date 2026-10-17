<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mobile\MobileTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileGuestSessionController extends Controller
{
    public function store(Request $request, MobileTokenIssuer $issuer): JsonResponse
    {
        $data = $request->validate(['installation_id' => ['required', 'uuid']]);
        // This is a non-loginable anonymous commerce principal, not customer
        // registration. No fabricated email/contact or known customer is used.
        $payload = DB::transaction(function () use ($data, $issuer): array {
            $guest = User::query()->create([
                'name' => 'Guest', 'email' => null, 'phone' => null,
                'password' => Str::password(64), 'role' => 'mobile_guest', 'is_active' => true,
            ]);
            $token = $guest->createToken('mobile-guest:'.$data['installation_id'], ['mobile:guest'], now()->addDays(7));

            return ['principal' => $issuer->user($guest), 'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at->toISOString(), 'guest' => true];
        });

        return response()->json(['data' => $payload], 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, MobileTokenIssuer $issuer): JsonResponse
    {
        abort_unless($request->user()->role === 'mobile_guest', 404);

        return response()->json(['data' => [
            'principal' => $issuer->user($request->user()), 'guest' => true,
            'expires_at' => $request->user()->currentAccessToken()->expires_at->toISOString(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'mobile_guest', 404);
        $request->user()->currentAccessToken()->delete();

        return response()->json(status: 204);
    }
}
