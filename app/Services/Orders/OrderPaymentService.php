<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\OrderPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderPaymentService
{
    /** @return array{payment_status:string,paid_amount_cents:int,payment_method:?string,remaining_amount_cents:int} */
    public function resolve(
        string $status,
        mixed $paidAmount,
        ?string $paymentMethod,
        int $totalCents,
        int $deliveryCents,
    ): array {
        if (! in_array($status, OrderPaymentStatus::statuses(false), true)) {
            throw ValidationException::withMessages(['payment_status' => 'اختر حالة دفع صحيحة.']);
        }

        $totalCents = max(0, $totalCents);
        $deliveryCents = min($totalCents, max(0, $deliveryCents));
        $paymentMethod = filled($paymentMethod) ? trim((string) $paymentMethod) : null;

        $behavior = OrderPaymentStatus::behavior($status);

        if ($behavior === OrderPaymentStatus::UNPAID) {
            $paidCents = 0;
            $paymentMethod = null;
        } else {
            if (! $paymentMethod || ! in_array($paymentMethod, OrderPaymentStatus::paymentMethods(), true)) {
                throw ValidationException::withMessages(['payment_method' => 'اختر طريقة الدفع.']);
            }

            $paidCents = match ($behavior) {
                OrderPaymentStatus::PARTIALLY_PAID => (int) round(max(0, (float) $paidAmount) * 100),
                OrderPaymentStatus::PAID_WITHOUT_SHIPPING => max(0, $totalCents - $deliveryCents),
                OrderPaymentStatus::PAID_IN_FULL => $totalCents,
            };

            if ($behavior === OrderPaymentStatus::PARTIALLY_PAID && ($paidCents <= 0 || $paidCents >= $totalCents)) {
                throw ValidationException::withMessages([
                    'paid_amount' => 'المبلغ المدفوع جزئياً يجب أن يكون أكبر من صفر وأقل من إجمالي الطلب.',
                ]);
            }

            if ($behavior === OrderPaymentStatus::PAID_WITHOUT_SHIPPING && $deliveryCents <= 0) {
                throw ValidationException::withMessages([
                    'payment_status' => 'لا يمكن اختيار مدفوع بدون شحن لأن الطلب لا يحتوي على مصاريف توصيل.',
                ]);
            }
        }

        return [
            'payment_status' => $status,
            'paid_amount_cents' => $paidCents,
            'payment_method' => $paymentMethod,
            'remaining_amount_cents' => max(0, $totalCents - $paidCents),
        ];
    }

    public function updateGroup(
        Order $representative,
        string $status,
        mixed $paidAmount,
        ?string $paymentMethod,
        User $admin,
        Request $request,
    ): array {
        return DB::transaction(function () use ($representative, $status, $paidAmount, $paymentMethod, $admin, $request): array {
            $orders = Order::query()
                ->where('checkout_group_key', $representative->checkoutGroupKey())
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            abort_if($orders->isEmpty(), 404);

            $group = app(AdminOrderGroupService::class)->present(
                $orders->load(['story:id,title,price', 'items.product:id,name_ar', 'items.variant:id,product_id,name_ar'])
            );
            $resolved = $this->resolve(
                $status,
                $paidAmount,
                $paymentMethod,
                (int) $group['total_cents'],
                (int) $group['delivery_cents'],
            );
            $old = [
                'payment_status' => $orders->first()->payment_status ?: OrderPaymentStatus::UNPAID,
                'paid_amount_cents' => (int) $orders->first()->paid_amount_cents,
                'payment_method' => $orders->first()->payment_method,
            ];

            foreach ($orders as $order) {
                $delivery = $order->delivery_details ?? [];
                $delivery['payment_status'] = $resolved['payment_status'];
                $delivery['payment_method'] = $resolved['payment_method'];
                $delivery['paid_amount'] = $resolved['paid_amount_cents'] / 100;
                $delivery['remaining_amount'] = $resolved['remaining_amount_cents'] / 100;

                $order->update([
                    'payment_status' => $resolved['payment_status'],
                    'paid_amount_cents' => $resolved['paid_amount_cents'],
                    'payment_method' => $resolved['payment_method'],
                    'payment_updated_by_user_id' => $admin->id,
                    'payment_updated_at' => now(),
                    'delivery_details' => $delivery,
                ]);
            }

            AdminActivityLogger::log(
                action: 'checkout.payment_updated',
                description: 'تحديث حالة دفع عملية الشراء: '.$representative->checkoutGroupKey(),
                subject: $orders->first(),
                properties: [
                    'checkout_group_key' => $representative->checkoutGroupKey(),
                    'old' => $old,
                    'new' => $resolved,
                    'total_cents' => $group['total_cents'],
                ],
                admin: $admin,
                request: $request,
            );

            return $resolved;
        });
    }
}
