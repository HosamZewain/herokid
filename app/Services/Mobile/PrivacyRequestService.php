<?php

namespace App\Services\Mobile;

use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Record a verified request, not an assertion that physical deletion occurred. */
class PrivacyRequestService
{
    public function submit(User $user, array $data): PrivacyRequest
    {
        return DB::transaction(function () use ($user, $data): PrivacyRequest {
            // Serialize submissions from web and mobile for the same account.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($data['request_type'] === 'account_deletion'
                && (! Hash::check((string) ($data['password'] ?? ''), $user->password)
                    || ($data['confirmation'] ?? null) !== 'DELETE_MY_ACCOUNT')) {
                throw ValidationException::withMessages(['confirmation' => 'تأكد من كلمة المرور ووافق على طلب حذف الحساب.']);
            }

            $existing = $user->privacyRequests()->where('request_type', $data['request_type'])
                ->whereIn('status', ['pending', 'processing', 'in_progress'])->first();
            if ($existing) {
                return $existing;
            }
            $privacy = $user->privacyRequests()->create([
                'request_type' => $data['request_type'],
                'subject_type' => $data['request_type'] === 'order_media_deletion' ? 'order' : null,
                'subject_uuid' => $data['subject_uuid'] ?? null,
                'status' => 'pending',
                'reason' => $data['reason'] ?? null,
                'scope' => match ($data['request_type']) {
                    'account_deletion' => ['account', 'child_profiles', 'reusable_child_media', 'sessions', 'devices'],
                    'order_media_deletion' => ['retained_order_media_subject_to_legal_and_fulfillment_requirements'],
                    default => ['account', 'orders', 'child_profiles', 'consents'],
                },
                'requested_at' => now(),
                'due_at' => now()->addDays(30),
            ]);
            if ($data['request_type'] === 'account_deletion') {
                $user->forceFill(['deletion_requested_at' => $privacy->requested_at, 'deletion_scheduled_for' => $privacy->due_at])->save();
            }

            return $privacy;
        });
    }
}
