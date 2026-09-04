<?php

namespace App\Services\Bosta;

use App\Models\BostaShipment;
use App\Models\Order;
use App\Support\OrderStatusRegistry;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BostaShipmentEligibilityService
{
    /** @return Collection<int, Order> */
    public function eligibleRepresentatives(int $limit = 50): Collection
    {
        $readyShippingStatuses = $this->readyShippingStatuses();
        $cancelledOrderStatuses = $this->cancelledOrderStatuses();
        $shippedGroups = BostaShipment::query()
            ->whereNotNull('bosta_delivery_id')
            ->pluck('checkout_group_key')
            ->all();

        $placeholders = implode(', ', array_fill(0, count($readyShippingStatuses), '?'));
        $cancelledPlaceholders = implode(', ', array_fill(0, count($cancelledOrderStatuses), '?'));
        $bindings = [...$readyShippingStatuses, ...$cancelledOrderStatuses];

        $eligibleKeys = Order::query()
            ->select('checkout_group_key')
            ->whereNotIn('checkout_group_key', $shippedGroups)
            ->groupBy('checkout_group_key')
            ->havingRaw(
                "COUNT(*) = SUM(CASE WHEN shipping_status IN ($placeholders) AND status NOT IN ($cancelledPlaceholders) THEN 1 ELSE 0 END)",
                $bindings,
            )
            ->orderByRaw('MAX(created_at) DESC')
            ->limit($limit)
            ->pluck('checkout_group_key');

        if ($eligibleKeys->isEmpty()) {
            return collect();
        }

        return Order::query()
            ->with('checkoutReference')
            ->whereIn('checkout_group_key', $eligibleKeys)
            ->latest()
            ->get()
            ->unique('checkout_group_key')
            ->sortBy(fn (Order $order): int => $eligibleKeys->search($order->checkoutGroupKey()))
            ->values();
    }

    /** @param Collection<int, Order> $orders */
    public function ensureEligible(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            throw ValidationException::withMessages(['order' => 'عملية الشراء غير متاحة للشحن.']);
        }

        if ($orders->contains(fn (Order $order): bool => in_array($order->status, $this->cancelledOrderStatuses(), true))) {
            throw ValidationException::withMessages(['order' => 'عملية الشراء ملغاة أو لا تحتاج إلى شحن عبر Bosta.']);
        }

        if ($orders->contains(fn (Order $order): bool => ! in_array($order->shipping_status, $this->readyShippingStatuses(), true))) {
            throw ValidationException::withMessages([
                'order' => 'يجب تحويل حالة الشحن لكل عناصر عملية الشراء إلى «جاهز للشحن» قبل إنشاء شحنة Bosta.',
            ]);
        }
    }

    public function isEligible(Collection $orders): bool
    {
        try {
            $this->ensureEligible($orders);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** @return array<int, string> */
    public function readyShippingStatuses(): array
    {
        return collect(OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'ready'))
            ->push('ready')
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function cancelledOrderStatuses(): array
    {
        return collect(OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'cancelled'))
            ->push('cancelled')
            ->unique()
            ->values()
            ->all();
    }
}
