<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\OrderPaymentStatus;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderGroupDiscountService
{
    public function __construct(
        private readonly AdminOrderGroupService $groups,
        private readonly OrderPaymentLedgerService $paymentLedger,
    ) {}

    /** @return array<string, mixed> */
    public function apply(
        Order $representative,
        string $type,
        float $value,
        string $mode,
        string $reason,
        User $admin,
        Request $request,
    ): array {
        return DB::transaction(function () use ($representative, $type, $value, $mode, $reason, $admin, $request): array {
            $orders = Order::query()
                ->where('checkout_group_key', $representative->checkoutGroupKey())
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            abort_if($orders->isEmpty(), 404);

            $orders->load([
                'story:id,title,price',
                'items.product:id,name_ar',
                'items.variant:id,product_id,name_ar',
                'checkoutReference:id,checkout_group_key,short_reference',
            ]);
            $before = $this->groups->present($orders);
            $itemsCents = (int) $before['items_cents'];
            $deliveryCents = (int) $before['delivery_cents'];
            $calculatedCents = $type === 'percentage'
                ? (int) round($itemsCents * $value / 100)
                : (int) round($value * 100);
            $discountCents = $mode === 'add'
                ? (int) $before['discount_cents'] + $calculatedCents
                : $calculatedCents;

            if ($calculatedCents <= 0) {
                throw ValidationException::withMessages([
                    'discount_value' => 'قيمة الخصم يجب أن تكون أكبر من صفر.',
                ]);
            }

            if ($discountCents > $itemsCents) {
                throw ValidationException::withMessages([
                    'discount_value' => 'لا يمكن أن يتجاوز إجمالي الخصم قيمة منتجات الطلب قبل الخصم. مصاريف التوصيل لا يشملها الخصم.',
                ]);
            }

            $totalCents = max(0, $itemsCents - $discountCents) + $deliveryCents;
            $payment = $this->recalculatePayment($before, $totalCents);
            $discountReason = $mode === 'add' && filled($before['discount_reason'])
                ? trim((string) $before['discount_reason']).' — '.trim($reason)
                : trim($reason);
            $paymentChanged = (string) $before['payment_status'] !== $payment['payment_status']
                || (int) $before['paid_amount_cents'] !== $payment['paid_amount_cents']
                || ($before['payment_method'] ?? null) !== $payment['payment_method'];

            foreach ($orders as $order) {
                $delivery = (array) ($order->delivery_details ?? []);
                $delivery['discount'] = $discountCents / 100;
                $delivery['total'] = $totalCents / 100;
                $delivery['payment_status'] = $payment['payment_status'];
                $delivery['payment_method'] = $payment['payment_method'];
                $delivery['paid_amount'] = $payment['paid_amount_cents'] / 100;
                $delivery['remaining_amount'] = $payment['remaining_amount_cents'] / 100;

                $order->forceFill([
                    'discount_cents' => $discountCents,
                    'discount_reason' => $discountReason,
                    'payment_status' => $payment['payment_status'],
                    'paid_amount_cents' => $payment['paid_amount_cents'],
                    'payment_method' => $payment['payment_method'],
                    'payment_updated_by_user_id' => $paymentChanged ? $admin->id : $order->payment_updated_by_user_id,
                    'payment_updated_at' => $paymentChanged ? now() : $order->payment_updated_at,
                    'delivery_details' => $delivery,
                ])->save();
            }

            $after = [
                ...$before,
                'discount_cents' => $discountCents,
                'discount_reason' => $discountReason,
                'total_cents' => $totalCents,
                'payment_status' => $payment['payment_status'],
                'paid_amount_cents' => $payment['paid_amount_cents'],
                'remaining_amount_cents' => $payment['remaining_amount_cents'],
                'payment_method' => $payment['payment_method'],
            ];

            AdminActivityLogger::log(
                action: 'checkout.discount_updated',
                description: 'تحديث خصم عملية الشراء: '.($before['short_reference'] ?: $representative->checkoutGroupKey()),
                subject: $orders->first(),
                properties: [
                    'checkout_group_key' => $representative->checkoutGroupKey(),
                    'discount_type' => $type,
                    'discount_mode' => $mode,
                    'discount_value' => $value,
                    'reason' => $reason,
                    'before' => [
                        'discount_cents' => (int) $before['discount_cents'],
                        'total_cents' => (int) $before['total_cents'],
                        'payment_status' => (string) $before['payment_status'],
                        'paid_amount_cents' => (int) $before['paid_amount_cents'],
                    ],
                    'after' => [
                        'discount_cents' => $discountCents,
                        'total_cents' => $totalCents,
                        'payment_status' => $payment['payment_status'],
                        'paid_amount_cents' => $payment['paid_amount_cents'],
                    ],
                ],
                admin: $admin,
                request: $request,
            );

            if ($paymentChanged) {
                $this->paymentLedger->recordTransition(
                    representative: $orders->first(),
                    before: [
                        'payment_status' => (string) $before['payment_status'],
                        'paid_amount_cents' => (int) $before['paid_amount_cents'],
                        'payment_method' => $before['payment_method'] ?? null,
                    ],
                    after: $payment,
                    source: 'admin_discount_update',
                    actor: $admin,
                    request: $request,
                    metadata: [
                        'discount_before_cents' => (int) $before['discount_cents'],
                        'discount_after_cents' => $discountCents,
                        'reason' => $reason,
                    ],
                    forcedEventType: 'discount_adjustment',
                    affectsCollectionStats: false,
                );
            }

            return $after;
        });
    }

    /** @return array{payment_status:string,paid_amount_cents:int,payment_method:?string,remaining_amount_cents:int} */
    private function recalculatePayment(array $group, int $totalCents): array
    {
        $status = (string) $group['payment_status'];
        $behavior = OrderPaymentStatus::behavior($status);
        $paidCents = max(0, (int) $group['paid_amount_cents']);
        $paymentMethod = $group['payment_method'] ?? null;

        if ($behavior === OrderPaymentStatus::UNPAID) {
            $paidCents = 0;
            $paymentMethod = null;
        } elseif ($behavior === OrderPaymentStatus::PAID_IN_FULL) {
            $paidCents = $totalCents;
        } elseif ($behavior === OrderPaymentStatus::PAID_WITHOUT_SHIPPING) {
            $paidCents = max(0, $totalCents - min($totalCents, (int) $group['delivery_cents']));
        } elseif ($behavior === OrderPaymentStatus::PARTIALLY_PAID && $paidCents >= $totalCents) {
            $paidInFullStatus = collect(OrderStatusRegistry::keysForBehavior(
                OrderStatusRegistry::TYPE_PAYMENT,
                OrderPaymentStatus::PAID_IN_FULL,
                true,
            ))->first();

            if (! $paidInFullStatus) {
                throw ValidationException::withMessages([
                    'discount_value' => 'الخصم يجعل الطلب مدفوعًا بالكامل، لكن حالة الدفع «مدفوع بالكامل» غير مفعلة في الإعدادات.',
                ]);
            }

            $status = $paidInFullStatus;
            $paidCents = $totalCents;
        }

        return [
            'payment_status' => $status,
            'paid_amount_cents' => min($totalCents, $paidCents),
            'payment_method' => $paymentMethod,
            'remaining_amount_cents' => max(0, $totalCents - $paidCents),
        ];
    }
}
