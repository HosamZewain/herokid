<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderAssignmentService
{
    public function acquire(Order $representative, User $admin, Request $request, bool $force = false): OrderGroupAssignment
    {
        $key = $representative->checkoutGroupKey();

        try {
            return DB::transaction(function () use ($representative, $admin, $request, $force, $key): OrderGroupAssignment {
                $assignment = OrderGroupAssignment::query()
                    ->with('assignee:id,name')
                    ->where('checkout_group_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($assignment && $assignment->assigned_to_user_id === $admin->id) {
                    return $assignment;
                }

                if ($assignment && ! $force) {
                    throw ValidationException::withMessages([
                        'assignment' => 'هذا الطلب مستلم بالفعل بواسطة '.$assignment->assignee?->name.'.',
                    ]);
                }

                $previousAssignee = $assignment?->assignee?->name;
                $assignment = OrderGroupAssignment::query()->updateOrCreate(
                    ['checkout_group_key' => $key],
                    [
                        'assigned_to_user_id' => $admin->id,
                        'assigned_by_user_id' => $admin->id,
                        'assigned_at' => now(),
                    ],
                );

                AdminActivityLogger::log(
                    action: $previousAssignee ? 'order.assignment_taken_over' : 'order.assignment_acquired',
                    description: $previousAssignee
                        ? 'تم نقل مسؤولية الطلب من '.$previousAssignee.' إلى '.$admin->name.'.'
                        : 'استلم '.$admin->name.' مسؤولية تنفيذ الطلب.',
                    subject: $representative,
                    properties: [
                        'checkout_group_key' => $key,
                        'previous_assignee' => $previousAssignee,
                        'assigned_to_user_id' => $admin->id,
                        'assigned_to_name' => $admin->name,
                    ],
                    admin: $admin,
                    request: $request,
                );

                return $assignment->load('assignee:id,name');
            });
        } catch (UniqueConstraintViolationException) {
            $assignment = OrderGroupAssignment::query()->with('assignee:id,name')->where('checkout_group_key', $key)->first();

            throw ValidationException::withMessages([
                'assignment' => 'سبق أن استلم '.$assignment?->assignee?->name.' هذا الطلب في نفس اللحظة. حدّث الصفحة واختر طلبًا آخر.',
            ]);
        }
    }

    public function release(Order $representative, User $admin, Request $request, bool $force = false): void
    {
        DB::transaction(function () use ($representative, $admin, $request, $force): void {
            $assignment = OrderGroupAssignment::query()
                ->with('assignee:id,name')
                ->where('checkout_group_key', $representative->checkoutGroupKey())
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                return;
            }

            if ($assignment->assigned_to_user_id !== $admin->id && ! $force) {
                throw ValidationException::withMessages([
                    'assignment' => 'لا يمكن ترك الطلب لأنه مستلم بواسطة '.$assignment->assignee?->name.'.',
                ]);
            }

            $releasedName = $assignment->assignee?->name;
            $assignment->delete();

            AdminActivityLogger::log(
                action: 'order.assignment_released',
                description: 'تم ترك مسؤولية الطلب التي كانت مسندة إلى '.$releasedName.'.',
                subject: $representative,
                properties: [
                    'checkout_group_key' => $representative->checkoutGroupKey(),
                    'released_user_id' => $assignment->assigned_to_user_id,
                    'released_user_name' => $releasedName,
                    'released_by_user_id' => $admin->id,
                ],
                admin: $admin,
                request: $request,
            );
        });
    }
}
