<?php

namespace App\Services\Mobile;

use App\Models\MobileOtpChallenge;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MobileOtpService
{
    public function create(string $phone, Request $request): array
    {
        $normalized = Phone::normalize($phone);
        if (! $normalized || strlen($normalized) < 10) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid mobile number.']);
        }
        $hash = hash('sha256', $normalized);
        if (MobileOtpChallenge::query()->where('phone_hash', $hash)->where('created_at', '>=', now()->subHour())->count() >= 5) {
            throw ValidationException::withMessages(['phone' => 'Too many verification codes were requested. Try again later.']);
        }
        $code = (string) random_int(100000, 999999);
        $this->send($normalized, $code);

        $challenge = MobileOtpChallenge::query()->create([
            'phone_hash' => $hash,
            'phone_encrypted' => $normalized,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'request_ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null,
        ]);

        return [
            'challenge_id' => $challenge->uuid,
            'expires_at' => $challenge->expires_at->toISOString(),
            'test_code' => app()->runningUnitTests() && config('services.mobile_otp.driver') === 'array' ? $code : null,
        ];
    }

    public function consume(string $uuid, string $code): string
    {
        $challenge = MobileOtpChallenge::query()->where('uuid', $uuid)->lockForUpdate()->first();
        if (! $challenge || $challenge->consumed_at || $challenge->expires_at->isPast() || $challenge->attempts >= 5) {
            throw ValidationException::withMessages(['code' => 'This verification code is expired or unavailable.']);
        }
        $challenge->increment('attempts');
        if (! Hash::check($code, $challenge->code_hash)) {
            throw ValidationException::withMessages(['code' => 'The verification code is incorrect.']);
        }
        $challenge->forceFill(['consumed_at' => now()])->save();

        return $challenge->phone_encrypted;
    }

    private function send(string $phone, string $code): void
    {
        $driver = (string) config('services.mobile_otp.driver', 'none');
        if ($driver === 'array' && app()->runningUnitTests()) {
            return;
        }
        if ($driver !== 'twilio' || ! config('services.twilio.sid') || ! config('services.twilio.token') || ! config('services.twilio.from')) {
            throw ValidationException::withMessages(['phone' => 'SMS verification is not configured on the HeroKid backend.']);
        }
        $destination = str_starts_with($phone, '01') ? '+20'.$phone : '+'.ltrim($phone, '+');
        $response = Http::asForm()
            ->withBasicAuth((string) config('services.twilio.sid'), (string) config('services.twilio.token'))
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.config('services.twilio.sid').'/Messages.json', [
                'From' => config('services.twilio.from'),
                'To' => $destination,
                'Body' => 'HeroKid verification code: '.$code.'. It expires in 10 minutes.',
            ]);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['phone' => 'The SMS provider could not send a verification code.']);
        }
    }
}
