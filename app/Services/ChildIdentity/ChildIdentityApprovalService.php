<?php

namespace App\Services\ChildIdentity;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChildIdentityApprovalService
{
    public function __construct(
        private readonly ChildIdentityAggregateService $aggregates,
        private readonly ChildIdentityEventLogger $events,
    ) {}

    public function approve(
        ChildIdentityRequest $identity,
        ChildIdentityGenerationAttempt $attempt,
        ?User $actor = null,
        string $source = 'web',
        bool $allowRejected = false,
    ): ChildIdentityRequest {
        return DB::transaction(function () use ($identity, $attempt, $actor, $source, $allowRejected): ChildIdentityRequest {
            $locked = ChildIdentityRequest::withTrashed()->lockForUpdate()->findOrFail($identity->id);
            $lockedAttempt = ChildIdentityGenerationAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $allowedStatuses = $allowRejected ? ['succeeded', 'rejected'] : ['succeeded'];

            if ($lockedAttempt->child_identity_request_id !== $locked->id
                || ! in_array($lockedAttempt->status, $allowedStatuses, true)
                || ! filled($lockedAttempt->output_storage_path)) {
                throw ValidationException::withMessages([
                    'attempt' => 'لا يمكن اعتماد هذه المحاولة لهذا الطلب.',
                ]);
            }

            $previous = $locked->approved_attempt_id;

            if ($lockedAttempt->status === 'rejected') {
                $lockedAttempt->forceFill(['status' => 'succeeded'])->save();
            }

            $fromStatus = $locked->status;
            $locked->forceFill([
                'approved_attempt_id' => $lockedAttempt->id,
                'status' => $locked->converted_at ? 'converted' : 'approved',
            ])->save();
            $this->syncLinkedOrders($locked, $lockedAttempt->id);
            $this->aggregates->recalculate($locked);
            $this->events->record(
                $locked,
                $source === 'admin' ? 'attempt.approved_by_admin' : 'attempt.approved',
                $source === 'admin' ? 'اعتمد المشرف محاولة الهوية.' : 'اعتمد ولي الأمر هوية الطفل.',
                [
                    'attempt_number' => $lockedAttempt->attempt_number,
                    'previous_attempt_id' => $previous,
                    'linked_order_updated' => $locked->orders()->withTrashed()->exists(),
                ],
                $lockedAttempt,
                $locked->convertedOrder,
                actor: $actor,
                actorType: $source === 'admin' ? 'admin' : 'customer',
                source: $source,
                fromStatus: $fromStatus,
                toStatus: $locked->status,
            );

            return $locked->fresh();
        });
    }

    public function reject(
        ChildIdentityRequest $identity,
        ChildIdentityGenerationAttempt $attempt,
        string $reason,
        User $actor,
    ): ChildIdentityRequest {
        return DB::transaction(function () use ($identity, $attempt, $reason, $actor): ChildIdentityRequest {
            $locked = ChildIdentityRequest::withTrashed()->lockForUpdate()->findOrFail($identity->id);
            $lockedAttempt = ChildIdentityGenerationAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($lockedAttempt->child_identity_request_id !== $locked->id || $lockedAttempt->status !== 'succeeded') {
                throw ValidationException::withMessages([
                    'attempt' => 'لا يمكن رفض هذه المحاولة لهذا الطلب.',
                ]);
            }

            $wasApproved = $locked->approved_attempt_id === $lockedAttempt->id;
            $lockedAttempt->forceFill([
                'status' => 'rejected',
                'safe_error_message' => $reason,
            ])->save();

            if ($wasApproved) {
                $locked->forceFill([
                    'approved_attempt_id' => null,
                    'status' => $locked->converted_at ? 'converted' : 'generated',
                ])->save();
                $this->syncLinkedOrders($locked, null);
            }

            $this->aggregates->recalculate($locked);
            $this->events->record(
                $locked,
                'attempt.rejected_by_admin',
                'رفض المشرف مخرج محاولة الهوية.',
                [
                    'reason' => $reason,
                    'was_approved' => $wasApproved,
                    'linked_order_updated' => $wasApproved && $locked->orders()->withTrashed()->exists(),
                ],
                $lockedAttempt,
                $locked->convertedOrder,
                actor: $actor,
                actorType: 'admin',
                source: 'admin',
            );

            return $locked->fresh();
        });
    }

    private function syncLinkedOrders(ChildIdentityRequest $identity, ?int $attemptId): void
    {
        Order::withTrashed()
            ->where('child_identity_request_id', $identity->id)
            ->update(['child_identity_approved_attempt_id' => $attemptId]);
    }
}
