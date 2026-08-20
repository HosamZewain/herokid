<?php

namespace App\Services\Mobile;

use App\Models\User;

class MobileTokenIssuer
{
    public function issue(User $user, string $deviceName): array
    {
        $token = $user->createToken($deviceName, ['mobile'], now()->addDays(90));

        return ['data' => [
            'user' => $this->user($user),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toISOString(),
        ]];
    }

    public function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'email_verified' => $user->email_verified_at !== null,
        ];
    }
}
