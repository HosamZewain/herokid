<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityShare;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

class ChildIdentityReferralService
{
    private const SESSION_KEY = 'child_identity_share_referral';

    public function __construct(private readonly ChildIdentityShareSettings $settings) {}

    public function remember(ChildIdentityShare $share, Request $request): void
    {
        if ($this->resolve($request)) {
            return;
        }

        $payload = [
            'share_id' => $share->id,
            'captured_at' => now()->timestamp,
        ];
        $request->session()->put(self::SESSION_KEY, $payload);
        Cookie::queue(
            'herokid_identity_referral',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $this->settings->attributionDays() * 24 * 60,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'Lax',
        );
    }

    public function resolve(Request $request): ?ChildIdentityShare
    {
        $payload = $request->session()->get(self::SESSION_KEY);

        if (! is_array($payload)) {
            $decoded = json_decode((string) $request->cookie('herokid_identity_referral'), true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($payload) || empty($payload['share_id']) || empty($payload['captured_at'])) {
            return null;
        }

        $capturedAt = Carbon::createFromTimestamp((int) $payload['captured_at']);
        if ($capturedAt->lt(now()->subDays($this->settings->attributionDays()))) {
            return null;
        }

        return ChildIdentityShare::query()
            ->whereKey($payload['share_id'])
            ->where('share_enabled', true)
            ->where('status', 'ready')
            ->first();
    }
}
