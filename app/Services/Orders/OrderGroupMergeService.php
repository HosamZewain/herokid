<?php

namespace App\Services\Orders;

use App\Models\CheckoutCustomerWorkflow;
use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\OrderCheckoutReference;
use App\Models\OrderGroupAssignment;
use App\Models\OrderGroupMergeAlias;
use App\Models\OrderPaymentProof;
use App\Models\OrderProductPreviewGallery;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\OrderPaymentStatus;
use App\Support\OrderStatusRegistry;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderGroupMergeService
{
    public function __construct(
        private readonly AdminOrderGroupService $groups,
        private readonly OrderPaymentLedgerService $paymentLedger,
    ) {}

    /** @return array<string, mixed> */
    public function merge(
        Order $targetRepresentative,
        string $sourceReference,
        string $reason,
        User $admin,
        Request $request,
    ): array {
        return DB::transaction(function () use ($targetRepresentative, $sourceReference, $reason, $admin, $request): array {
            $targetKey = $targetRepresentative->checkoutGroupKey();
            $sourceKey = $this->resolveGroupKey($sourceReference);

            if ($sourceKey === $targetKey) {
                throw ValidationException::withMessages(['source_reference' => 'هذا المرجع تابع لنفس عملية الشراء الحالية بالفعل.']);
            }

            $keys = collect([$targetKey, $sourceKey])->sort()->values();
            $locked = Order::query()
                ->whereIn('checkout_group_key', $keys)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();
            $targetOrders = $locked->where('checkout_group_key', $targetKey)->values();
            $sourceOrders = $locked->where('checkout_group_key', $sourceKey)->values();

            if ($targetOrders->isEmpty() || $sourceOrders->isEmpty()) {
                throw ValidationException::withMessages(['source_reference' => 'تعذر العثور على عمليتي الشراء النشطتين.']);
            }

            $this->assertMergeable($targetOrders, $sourceOrders);

            $target = $this->groups->present($this->loadForPresentation($targetOrders));
            $source = $this->groups->present($this->loadForPresentation($sourceOrders));
            $targetReference = OrderCheckoutReference::query()->where('checkout_group_key', $targetKey)->first();
            $sourceCheckoutReference = OrderCheckoutReference::query()->where('checkout_group_key', $sourceKey)->first();
            $combinedItemsCents = (int) $target['items_cents'] + (int) $source['items_cents'];
            $combinedDiscountCents = (int) $target['discount_cents'] + (int) $source['discount_cents'];
            $deliveryCents = (int) $target['delivery_cents'];
            $combinedTotalCents = max(0, $combinedItemsCents + $deliveryCents - $combinedDiscountCents);
            $combinedPaidCents = (int) $target['paid_amount_cents'] + (int) $source['paid_amount_cents'];

            if ($combinedPaidCents > $combinedTotalCents) {
                throw ValidationException::withMessages([
                    'source_reference' => 'لا يمكن الدمج لأن إجمالي المدفوع في الطلبين أكبر من الإجمالي الجديد بعد حذف الشحن المكرر. سوِّ فرق الدفع أولاً.',
                ]);
            }

            $paymentMethod = $this->combinedPaymentMethod($targetOrders, $sourceOrders, $combinedPaidCents);
            $paymentStatus = $this->paymentStatus($combinedPaidCents, $combinedTotalCents, $deliveryCents);
            $primaryDelivery = $target['delivery'] ?? [];
            $mergedOrderIds = $sourceOrders->pluck('id')->all();
            $allOrders = $targetOrders->concat($sourceOrders)->values();

            foreach ($allOrders as $order) {
                $delivery = array_merge($order->delivery_details ?? [], $primaryDelivery, [
                    'checkout_group' => $targetKey,
                    'subtotal' => $combinedItemsCents / 100,
                    'delivery_fee' => $deliveryCents / 100,
                    'discount' => $combinedDiscountCents / 100,
                    'total' => $combinedTotalCents / 100,
                    'payment_status' => $paymentStatus,
                    'payment_method' => $paymentMethod,
                    'paid_amount' => $combinedPaidCents / 100,
                    'remaining_amount' => max(0, $combinedTotalCents - $combinedPaidCents) / 100,
                ]);

                $order->forceFill([
                    'checkout_group_key' => $targetKey,
                    'parent_name' => $target['customer_name'],
                    'delivery_details' => $delivery,
                    'discount_cents' => $combinedDiscountCents,
                    'discount_reason' => $this->combinedDiscountReason($target, $source),
                    'payment_status' => $paymentStatus,
                    'paid_amount_cents' => $combinedPaidCents,
                    'payment_method' => $paymentMethod,
                    'payment_updated_by_user_id' => $admin->id,
                    'payment_updated_at' => now(),
                ])->save();
            }

            $this->mergeAssignment($targetKey, $sourceKey);
            OrderAdminNote::query()->where('checkout_group_key', $sourceKey)->update(['checkout_group_key' => $targetKey]);
            OrderPaymentProof::query()->where('checkout_group_key', $sourceKey)->update(['checkout_group_key' => $targetKey]);
            RoboDeskIntegrationEvent::query()->where('checkout_group_key', $sourceKey)->update(['checkout_group_key' => $targetKey]);
            $this->mergeProductPreviewGallery($targetKey, $sourceKey);
            $this->mergeCustomerWorkflow($targetKey, $sourceKey);

            OrderGroupMergeAlias::query()->create([
                'source_checkout_group_key' => $sourceKey,
                'target_checkout_group_key' => $targetKey,
                'source_short_reference' => $sourceCheckoutReference?->short_reference,
                'target_short_reference' => $targetReference?->short_reference,
                'source_representative_order_id' => $sourceOrders->first()->id,
                'target_representative_order_id' => $targetOrders->first()->id,
                'merged_by_user_id' => $admin->id,
                'removed_delivery_fee_cents' => (int) $source['delivery_cents'],
                'reason' => trim($reason),
                'merged_at' => now(),
                'metadata' => [
                    'moved_order_ids' => $mergedOrderIds,
                    'target_order_ids' => $targetOrders->pluck('id')->all(),
                    'before' => [
                        'target_total_cents' => (int) $target['total_cents'],
                        'source_total_cents' => (int) $source['total_cents'],
                        'target_delivery_cents' => (int) $target['delivery_cents'],
                        'source_delivery_cents' => (int) $source['delivery_cents'],
                    ],
                    'after' => [
                        'items_cents' => $combinedItemsCents,
                        'delivery_cents' => $deliveryCents,
                        'discount_cents' => $combinedDiscountCents,
                        'total_cents' => $combinedTotalCents,
                        'paid_amount_cents' => $combinedPaidCents,
                    ],
                ],
            ]);

            $representative = $targetOrders->first()->fresh();
            $this->paymentLedger->recordTransition(
                representative: $representative,
                before: [
                    'payment_status' => $target['payment_status'],
                    'paid_amount_cents' => (int) $target['paid_amount_cents'],
                    'payment_method' => $target['payment_method'],
                ],
                after: [
                    'payment_status' => $paymentStatus,
                    'paid_amount_cents' => $combinedPaidCents,
                    'payment_method' => $paymentMethod,
                ],
                source: 'order_group_merge',
                actor: $admin,
                request: $request,
                metadata: [
                    'source_checkout_group_key' => $sourceKey,
                    'source_paid_amount_cents' => (int) $source['paid_amount_cents'],
                    'reconciliation_only' => true,
                ],
                forcedEventType: 'merge_reconciliation',
            );

            AdminActivityLogger::log(
                action: 'checkout.groups_merged',
                description: 'تم دمج عملية الشراء '.$sourceReference.' داخل '.($targetReference?->short_reference ?: $targetKey).' واحتساب الشحن مرة واحدة.',
                subject: $representative,
                properties: [
                    'target_checkout_group_key' => $targetKey,
                    'source_checkout_group_key' => $sourceKey,
                    'source_short_reference' => $sourceCheckoutReference?->short_reference,
                    'target_short_reference' => $targetReference?->short_reference,
                    'moved_order_ids' => $mergedOrderIds,
                    'removed_delivery_fee_cents' => (int) $source['delivery_cents'],
                    'new_total_cents' => $combinedTotalCents,
                    'reason' => trim($reason),
                ],
                admin: $admin,
                request: $request,
            );

            return $this->groups->findByRepresentative($representative->id);
        });
    }

    private function mergeProductPreviewGallery(string $targetKey, string $sourceKey): void
    {
        $galleries = OrderProductPreviewGallery::query()
            ->whereIn('checkout_group_key', [$targetKey, $sourceKey])
            ->lockForUpdate()
            ->get()
            ->keyBy('checkout_group_key');
        $target = $galleries->get($targetKey);
        $source = $galleries->get($sourceKey);

        if (! $source) {
            return;
        }

        if (! $target) {
            $source->update(['checkout_group_key' => $targetKey]);

            return;
        }

        $source->previews()->update(['product_gallery_id' => $target->id]);
        $source->delete();
    }

    private function resolveGroupKey(string $reference): string
    {
        $reference = trim($reference);
        $aliasTarget = OrderGroupMergeAlias::query()
            ->where('source_short_reference', $reference)
            ->orWhere('source_checkout_group_key', $reference)
            ->value('target_checkout_group_key');

        if ($aliasTarget) {
            return (string) $aliasTarget;
        }

        $key = OrderCheckoutReference::query()
            ->where('short_reference', $reference)
            ->orWhere('checkout_group_key', $reference)
            ->value('checkout_group_key');

        if ($key && Order::query()->where('checkout_group_key', $key)->exists()) {
            return (string) $key;
        }

        $orderQuery = Order::query()->where('order_number', $reference);
        if (ctype_digit($reference)) {
            $orderQuery->orWhereKey((int) $reference);
        }
        $order = $orderQuery->first();

        if (! $order) {
            throw ValidationException::withMessages(['source_reference' => 'لم يتم العثور على طلب نشط بهذا المرجع.']);
        }

        return $order->checkoutGroupKey();
    }

    private function assertMergeable(Collection $targetOrders, Collection $sourceOrders): void
    {
        $targetPhone = Phone::forWhatsApp(data_get($targetOrders->first()->delivery_details, 'phone'));
        $sourcePhone = Phone::forWhatsApp(data_get($sourceOrders->first()->delivery_details, 'phone'));

        if (! $targetPhone || ! $sourcePhone || $targetPhone !== $sourcePhone) {
            throw ValidationException::withMessages(['source_reference' => 'يجب أن يكون رقما الهاتف في الطلبين متطابقين قبل الدمج لحماية طلبات العملاء.']);
        }

        foreach ([$targetOrders, $sourceOrders] as $checkoutOrders) {
            $orderBehaviors = $checkoutOrders
                ->map(fn (Order $order): string => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $order->status));
            $shippingBehaviors = $checkoutOrders
                ->map(fn (Order $order): string => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $order->shipping_status));

            // A checkout is cancelled only when every live record is cancelled. Older mixed
            // story/product checkouts may retain a cancelled product shell after reactivation.
            if ($orderBehaviors->isNotEmpty() && $orderBehaviors->every(fn (string $behavior): bool => $behavior === 'cancelled')) {
                throw ValidationException::withMessages(['source_reference' => 'لا يمكن دمج طلب ملغي بالكامل. أعد تفعيله أولًا.']);
            }

            // Apply the same checkout-level rule to legacy shipping data. A stale cancelled
            // product record must not override the active not-ready status shown for the checkout.
            if ($shippingBehaviors->isNotEmpty() && $shippingBehaviors->every(fn (string $behavior): bool => $behavior === 'cancelled')) {
                throw ValidationException::withMessages(['source_reference' => 'لا يمكن دمج طلب أُلغي بالكامل من الشحن. غيّر حالة الشحن أولًا.']);
            }

            foreach ($checkoutOrders as $order) {
                $shippingBehavior = OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $order->shipping_status);
                if (in_array($shippingBehavior, ['shipped', 'delivered', 'returned'], true)) {
                    $orderLabel = OrderStatusRegistry::label(OrderStatusRegistry::TYPE_ORDER, $order->status);
                    $shippingLabel = OrderStatusRegistry::label(OrderStatusRegistry::TYPE_SHIPPING, $order->shipping_status);
                    throw ValidationException::withMessages([
                        'source_reference' => 'تعذر الدمج بسبب السجل '.$order->order_number.' (حالة الطلب: '.$orderLabel.'، حالة الشحن: '.$shippingLabel.').',
                    ]);
                }
            }
        }
    }

    private function loadForPresentation(Collection $orders): Collection
    {
        return $orders->load([
            'user:id,name,role',
            'createdByAdmin:id,name',
            'paymentUpdatedBy:id,name',
            'groupAssignment.assignee:id,name',
            'checkoutReference:id,checkout_group_key,short_reference,reference_month,monthly_sequence',
            'story:id,title,price',
            'items.product:id,name_ar,inventory_mode,stock_quantity,production_prompt_template',
            'items.variant:id,product_id,name_ar,sku,stock_quantity',
        ]);
    }

    private function combinedPaymentMethod(Collection $targetOrders, Collection $sourceOrders, int $paidCents): ?string
    {
        if ($paidCents === 0) {
            return null;
        }

        $methods = $targetOrders->concat($sourceOrders)->pluck('payment_method')->filter()->unique()->values();
        if ($methods->count() > 1) {
            throw ValidationException::withMessages(['source_reference' => 'الطلبان يحتويان على طريقتي دفع مختلفتين. وحّد طريقة الدفع قبل الدمج.']);
        }

        return $methods->first();
    }

    private function paymentStatus(int $paidCents, int $totalCents, int $deliveryCents): string
    {
        if ($paidCents <= 0) {
            return OrderPaymentStatus::UNPAID;
        }
        if ($paidCents === $totalCents) {
            return OrderPaymentStatus::PAID_IN_FULL;
        }
        if ($deliveryCents > 0 && $paidCents === $totalCents - $deliveryCents) {
            return OrderPaymentStatus::PAID_WITHOUT_SHIPPING;
        }

        return OrderPaymentStatus::PARTIALLY_PAID;
    }

    /** @param array<string, mixed> $target
     * @param  array<string, mixed>  $source
     */
    private function combinedDiscountReason(array $target, array $source): ?string
    {
        return collect([$target['discount_reason'] ?? null, $source['discount_reason'] ?? null])
            ->filter()
            ->unique()
            ->implode(' + ') ?: null;
    }

    private function mergeAssignment(string $targetKey, string $sourceKey): void
    {
        $target = OrderGroupAssignment::query()->where('checkout_group_key', $targetKey)->lockForUpdate()->first();
        $source = OrderGroupAssignment::query()->where('checkout_group_key', $sourceKey)->lockForUpdate()->first();
        if (! $target && $source) {
            $source->update(['checkout_group_key' => $targetKey]);
        } elseif ($source) {
            $source->delete();
        }
    }

    private function mergeCustomerWorkflow(string $targetKey, string $sourceKey): void
    {
        $target = CheckoutCustomerWorkflow::query()->where('checkout_group_key', $targetKey)->lockForUpdate()->first();
        $source = CheckoutCustomerWorkflow::query()->where('checkout_group_key', $sourceKey)->lockForUpdate()->first();
        if (! $target && $source) {
            $source->update(['checkout_group_key' => $targetKey]);

            return;
        }
        if ($target && $source) {
            $metadata = $target->metadata ?? [];
            $metadata['merged_workflows'][] = [
                'source_checkout_group_key' => $sourceKey,
                'confirmation_status' => $source->confirmation_status,
                'payment_request_status' => $source->payment_request_status,
                'merged_at' => now()->toIso8601String(),
            ];
            $target->update(['metadata' => $metadata]);
            $source->delete();
        }
    }
}
