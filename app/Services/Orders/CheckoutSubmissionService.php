<?php

namespace App\Services\Orders;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutSubmissionService
{
    public function token(Request $request, array $cart): string
    {
        return hash_hmac('sha256', $this->sessionHash($request).'|'.json_encode($cart, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function completed(Request $request): ?array
    {
        $token = $request->input('checkout_submission_token');
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $ids = DB::table('checkout_submissions')->where('key_hash', $token)
            ->where('session_hash', $this->sessionHash($request))->value('order_ids');

        return $ids ? json_decode($ids, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    /** Must run inside the same transaction as the purchase writes. */
    public function claim(Request $request, array $cart): array
    {
        $key = $this->token($request, $cart);
        $provided = $request->input('checkout_submission_token');
        if ($provided !== null && (! is_string($provided) || ! hash_equals($key, $provided))) {
            throw ValidationException::withMessages(['checkout_submission_token' => 'تغيرت السلة. حدّث الصفحة ثم أكد الطلب مرة أخرى.']);
        }
        DB::table('checkout_submissions')->insertOrIgnore([
            'key_hash' => $key, 'session_hash' => $this->sessionHash($request),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $record = DB::table('checkout_submissions')->where('key_hash', $key)->lockForUpdate()->firstOrFail();

        return ['key' => $key, 'order_ids' => $record->order_ids ? json_decode($record->order_ids, true, 512, JSON_THROW_ON_ERROR) : null];
    }

    public function complete(string $key, array $orderIds): void
    {
        DB::table('checkout_submissions')->where('key_hash', $key)->update([
            'order_ids' => json_encode($orderIds, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }

    private function sessionHash(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));
    }
}
