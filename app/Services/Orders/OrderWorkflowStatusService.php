<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderWorkflowStatusService
{
    public function __construct(
        private readonly AdminOrderGroupService $groups,
        private readonly OrderStatusService $orderStatuses,
        private readonly OrderPaymentService $payments,
    ) {}

    public function updateGroup(
        Order $representative,
        array $values,
        User $admin,
        Request $request,
    ): array {
        return DB::transaction(function () use ($representative, $values, $admin, $request): array {
            $orders = Order::query()
                ->where('checkout_group_key', $representative->checkoutGroupKey())
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            abort_if($orders->isEmpty(), 404);

            $before = $this->groups->present($orders->load([
                'story:id,title,price',
                'items.product:id,name_ar',
                'items.variant:id,product_id,name_ar',
            ]));

            if (filled($values['status'] ?? null)) {
                $this->orderStatuses->updateGroup(
                    $orders,
                    $values['status'],
                    $values['admin_notes'] ?? null,
                    $request,
                );
            }

            $this->payments->updateGroup(
                $orders->first(),
                $values['payment_status'],
                $values['paid_amount'] ?? null,
                $values['payment_method'] ?? null,
                $admin,
                $request,
            );

            $workflowChanges = [];
            foreach ($orders as $order) {
                foreach (['printing_status' => 'printing', 'shipping_status' => 'shipping'] as $column => $type) {
                    $oldStatus = $order->{$column};
                    $newStatus = $values[$column];

                    if ($oldStatus === $newStatus) {
                        continue;
                    }

                    $order->{$column} = $newStatus;
                    $order->statusLogs()->create([
                        'status_type' => $type,
                        'status' => $newStatus,
                        'notes' => $values['admin_notes'] ?? 'تم تحديث الحالة من لوحة الإدارة.',
                    ]);
                    $workflowChanges[$order->id][$column] = [
                        'old' => $oldStatus,
                        'new' => $newStatus,
                    ];
                }

                if (isset($workflowChanges[$order->id])) {
                    $order->workflow_status_updated_by_user_id = $admin->id;
                    $order->workflow_status_updated_at = now();
                    $order->save();
                }
            }

            $after = $this->groups->findByRepresentative($orders->first()->id);

            AdminActivityLogger::log(
                action: 'checkout.workflow_statuses_updated',
                description: 'تحديث حالات عملية الشراء: '.$representative->checkoutGroupKey(),
                subject: $orders->first(),
                properties: [
                    'checkout_group_key' => $representative->checkoutGroupKey(),
                    'before' => [
                        'status' => $before['status'],
                        'payment_status' => $before['payment_status'],
                        'printing_status' => $before['printing_status'],
                        'shipping_status' => $before['shipping_status'],
                    ],
                    'after' => [
                        'status' => $after['status'],
                        'payment_status' => $after['payment_status'],
                        'printing_status' => $after['printing_status'],
                        'shipping_status' => $after['shipping_status'],
                    ],
                    'workflow_changes' => $workflowChanges,
                    'admin_notes' => $values['admin_notes'] ?? null,
                ],
                admin: $admin,
                request: $request,
            );

            return $after;
        });
    }
}
