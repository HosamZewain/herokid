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
    public function assign(Order $order, ?User $target, User $actor, Request $request, ?int $expectedAssignee, int $expectedVersion, string $reason): void
    {
        abort_unless($actor->is_active && ! $actor->agent_api_enabled && $actor->hasPermission('orders.assignment.manage'), 403);
        app(OrderAccess::class)->assert($order);
        abort_if($target && (! $target->is_active || $target->agent_api_enabled || ! $target->isAdmin() || ! $target->hasPermission('orders.view')), 422, 'اختر موظفًا نشطًا لديه صلاحية عرض الطلبات.');
        DB::transaction(function () use ($order, $target, $actor, $request, $expectedAssignee, $expectedVersion, $reason) {
            // Checkout lock also serializes the first assignment when no assignment row exists.
            Order::where('checkout_group_key', $order->checkoutGroupKey())->orderBy('id')->lockForUpdate()->get();
            DB::table('order_automation_controls')->insertOrIgnore(['checkout_key' => $order->checkoutGroupKey(), 'created_at' => now(), 'updated_at' => now()]);
            $control = DB::table('order_automation_controls')->where('checkout_key', $order->checkoutGroupKey())->lockForUpdate()->first();
            $current = OrderGroupAssignment::where('checkout_group_key', $order->checkoutGroupKey())->lockForUpdate()->first();
            abort_unless(($current?->assigned_to_user_id) === $expectedAssignee && (int) $control->assignment_version === $expectedVersion, 409, 'تغيّر المسؤول عن الطلب؛ حدّث الصفحة قبل إعادة الإسناد.');
            if (($current?->assigned_to_user_id) === $target?->id) {
                return;
            }
            $before = $current?->assigned_to_user_id;
            DB::table('order_automation_controls')->where('checkout_key', $order->checkoutGroupKey())->update(['assignment_version' => $expectedVersion + 1, 'updated_at' => now()]);
            if ($target) {
                OrderGroupAssignment::updateOrCreate(['checkout_group_key' => $order->checkoutGroupKey()], [
                    'assigned_to_user_id' => $target->id, 'assigned_by_user_id' => $actor->id,
                    'assigned_at' => now(), 'version' => ($current?->version ?? 0) + 1,
                ]);
            } else {
                $current?->delete();
            }
            AdminActivityLogger::log('order.assignment_changed', 'تغيير الموظف المسؤول دون تغيير تنفيذ الأوتوميشن.', $order,
                ['checkout_group_key' => $order->checkoutGroupKey(), 'previous_assignee_id' => $before, 'assigned_to_user_id' => $target?->id, 'reason' => $reason], $actor, $request);
        }, 3);
    }

    public function acquire(Order $representative, User $admin, Request $request, bool $force = false): OrderGroupAssignment
    {
        abort_unless($admin->is_active && $admin->hasPermission('orders.assignment.manage'), 403);
        app(OrderAccess::class)->assert($representative);
        if ($admin->agent_api_enabled) {
            throw ValidationException::withMessages([
                'assignment' => 'الاستحواذ على الطلبات متاح للموظفين فقط، وليس لحسابات Agent API.',
            ]);
        }

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
        abort_unless($admin->is_active && $admin->hasPermission('orders.assignment.manage'), 403);
        app(OrderAccess::class)->assert($representative);
        if ($admin->agent_api_enabled) {
            throw ValidationException::withMessages([
                'assignment' => 'إدارة استحواذ الطلبات متاحة للموظفين فقط، وليس لحسابات Agent API.',
            ]);
        }

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
