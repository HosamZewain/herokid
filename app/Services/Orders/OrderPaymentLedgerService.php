<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\User;
use App\Support\OrderPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrderPaymentLedgerService
{
    /** @param array{payment_status?:string,paid_amount_cents?:int,payment_method?:?string} $before
     * @param  array{payment_status?:string,paid_amount_cents?:int,payment_method?:?string}  $after
     */
    public function recordTransition(
        Order $representative,
        array $before,
        array $after,
        string $source,
        ?User $actor = null,
        ?Request $request = null,
        array $metadata = [],
        ?string $forcedEventType = null,
        ?bool $affectsCollectionStats = null,
    ): ?OrderPaymentEvent {
        $previousStatus = (string) ($before['payment_status'] ?? OrderPaymentStatus::UNPAID);
        $newStatus = (string) ($after['payment_status'] ?? OrderPaymentStatus::UNPAID);
        $previousPaid = max(0, (int) ($before['paid_amount_cents'] ?? 0));
        $newPaid = max(0, (int) ($after['paid_amount_cents'] ?? 0));
        $previousMethod = filled($before['payment_method'] ?? null) ? (string) $before['payment_method'] : null;
        $newMethod = filled($after['payment_method'] ?? null) ? (string) $after['payment_method'] : null;

        if ($forcedEventType === null
            && $previousStatus === $newStatus
            && $previousPaid === $newPaid
            && $previousMethod === $newMethod) {
            return null;
        }

        $delta = $newPaid - $previousPaid;
        $eventType = $forcedEventType ?? match (true) {
            $delta > 0 => 'payment_received',
            $delta < 0 => 'payment_reversed',
            default => 'payment_status_changed',
        };
        $affectsCollectionStats ??= in_array($eventType, ['payment_received', 'payment_reversed'], true);

        return OrderPaymentEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'checkout_group_key' => $representative->checkoutGroupKey(),
            'order_id' => $representative->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'source' => $source,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'previous_paid_amount_cents' => $previousPaid,
            'new_paid_amount_cents' => $newPaid,
            'amount_delta_cents' => $eventType === 'merge_reconciliation' ? 0 : $delta,
            'affects_collection_stats' => $eventType === 'merge_reconciliation' ? false : $affectsCollectionStats,
            'payment_method' => $newMethod,
            'occurred_at' => now(),
            'metadata' => [
                ...$metadata,
                'route_name' => $request?->route()?->getName(),
                'ip_address' => $request?->ip(),
            ],
        ]);
    }

    public function recordInitial(Order $order): ?OrderPaymentEvent
    {
        if (OrderPaymentEvent::query()
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->whereIn('source', ['admin_order_creation', 'checkout_creation'])
            ->exists()) {
            return null;
        }

        return $this->recordTransition(
            representative: $order,
            before: [
                'payment_status' => OrderPaymentStatus::UNPAID,
                'paid_amount_cents' => 0,
                'payment_method' => null,
            ],
            after: [
                'payment_status' => $order->payment_status ?: OrderPaymentStatus::UNPAID,
                'paid_amount_cents' => (int) $order->paid_amount_cents,
                'payment_method' => $order->payment_method,
            ],
            source: $order->created_by_admin_id ? 'admin_order_creation' : 'checkout_creation',
            actor: $order->createdByAdmin,
            metadata: ['initial_state' => true],
            forcedEventType: (int) $order->paid_amount_cents > 0 ? 'payment_received' : 'payment_initialized',
        );
    }

    /** @return Collection<int, OrderPaymentEvent> */
    public function forCheckout(string $checkoutGroupKey): Collection
    {
        return OrderPaymentEvent::query()
            ->with('actor:id,name')
            ->where('checkout_group_key', $checkoutGroupKey)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }
}
