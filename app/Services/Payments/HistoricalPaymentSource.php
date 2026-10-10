<?php

namespace App\Services\Payments;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderPaymentEvent;
use Illuminate\Support\Collection;

/** Read-only projection of pre-ledger receipts. Never invent a payment date. */
class HistoricalPaymentSource
{
    private ?array $result = null;

    public function history(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }
        $baselines = OrderPaymentEvent::query()->where('event_type', 'legacy_baseline')->orderBy('id')->get();
        $baselineCounts = $baselines->countBy('checkout_group_key');
        $events = collect();
        $undated = collect();
        $issues = collect();
        $corrections = collect();
        foreach ($baselines->chunk(200) as $batch) {
            $logs = AdminActivityLog::query()->select(['id', 'user_id', 'action', 'properties', 'created_at'])
                ->with('user:id,name')
                ->whereIn('action', ['checkout.payment_updated', 'order.created_manually', 'checkout.full_order_updated', 'checkout.discount_updated'])
                ->whereIn('properties->checkout_group_key', $batch->pluck('checkout_group_key'))
                ->where('created_at', '<=', $batch->max('created_at'))->orderBy('created_at')->orderBy('id')->get()
                ->groupBy(fn (AdminActivityLog $log): string => (string) data_get($log->properties, 'checkout_group_key'));
            $alreadyDated = OrderPaymentEvent::query()->whereIn('checkout_group_key', $batch->pluck('checkout_group_key'))
                ->where('affects_collection_stats', true)->where('occurred_at', '<=', $batch->max('created_at'))
                ->get(['checkout_group_key', 'occurred_at'])->groupBy('checkout_group_key');
            $projections = [];
            foreach ($batch as $baseline) {
                $key = $baseline->checkout_group_key;
                $same = $logs->get($key, collect())->filter(fn ($log) => $log->created_at->lt($baseline->created_at));
                $duplicate = $baselineCounts->get($key, 0) > 1;
                $overlap = $alreadyDated->get($key, collect())->contains(fn ($event) => $event->occurred_at->lte($baseline->created_at));
                $projected = $duplicate || $overlap ? null : $this->project($baseline, $same);
                $projections[$baseline->id] = ['page' => $projected,
                    'reason' => $duplicate ? 'duplicate_baseline' : ($overlap ? 'overlapping_ledger' : 'incomplete_history')];
            }
            $mismatches = $batch->filter(fn ($baseline): bool => ($projections[$baseline->id]['page'] ?? null) !== null
                && $projections[$baseline->id]['page']['end_cents'] !== (int) $baseline->new_paid_amount_cents);
            // One compact query per batch, not one query per mismatched checkout.
            $states = $mismatches->isEmpty() ? collect() : Order::withTrashed()
                ->whereIn('checkout_group_key', $mismatches->pluck('checkout_group_key'))
                ->orderBy('id')->get(['id', 'checkout_group_key', 'paid_amount_cents', 'created_at', 'deleted_at', 'payment_updated_at'])
                ->groupBy('checkout_group_key');
            $laterStates = $mismatches->isEmpty() ? collect() : OrderPaymentEvent::query()
                ->whereIn('checkout_group_key', $mismatches->pluck('checkout_group_key'))
                ->whereNotIn('event_type', ['legacy_baseline', 'payment_initialized'])
                ->where('created_at', '>=', $mismatches->min('created_at'))
                ->orderBy('created_at')->orderBy('id')
                ->get(['id', 'checkout_group_key', 'order_id', 'event_type', 'previous_paid_amount_cents', 'created_at', 'occurred_at'])
                ->groupBy('checkout_group_key');
            foreach ($batch as $baseline) {
                $key = $baseline->checkout_group_key;
                $projected = $projections[$baseline->id]['page'];
                if ($projected !== null && $projected['end_cents'] !== (int) $baseline->new_paid_amount_cents) {
                    $correction = $this->deletedCarrierCorrection($baseline, $projected, $states->get($key, collect()), $laterStates->get($key, collect()));
                    if ($correction === null) {
                        $projected = null;
                    } else {
                        $corrections->push($correction);
                        foreach ($projected['events'] as $event) {
                            $event->order_id = $correction['order_id'];
                            $event->metadata = [...$event->metadata, 'baseline_carrier_corrected' => true,
                                'discarded_baseline_order_id' => $correction['discarded_order_id']];
                        }
                    }
                }
                if ($projected === null) {
                    $issues->push(['key' => $key, 'order_id' => $baseline->order_id,
                        'reason' => $projections[$baseline->id]['reason']]);
                    $undated->push(['key' => $key, 'cents' => (int) $baseline->new_paid_amount_cents]);

                    continue;
                }
                $events = $events->concat($projected['events']);
                $undated->push(['key' => $key, 'cents' => $projected['opening_cents']]);
            }
        }

        return $this->result = ['events' => $events->values(), 'undated' => $undated,
            'baseline_cents' => (int) $baselines->sum('new_paid_amount_cents'),
            'baseline_correction_cents' => (int) $corrections->sum('delta_cents'), 'baseline_corrections' => $corrections,
            'recovered_net_cents' => (int) $events->sum('amount_delta_cents'),
            'undated_cents' => (int) $undated->sum('cents'), 'issues' => $issues,
            'baseline_captured_at' => $baselines->min('created_at')];
    }

    private function project(OrderPaymentEvent $baseline, Collection $logs): ?array
    {
        $balance = null;
        $opening = 0;
        $events = collect();
        $lastPaymentLog = null;
        foreach ($logs as $log) {
            $p = $log->properties ?? [];
            if (in_array($log->action, ['checkout.full_order_updated', 'checkout.discount_updated'], true)) {
                $before = data_get($p, 'before.paid_amount_cents');
                $after = data_get($p, 'after.paid_amount_cents');
                // The old editor could revalue payment without collecting money.
                // A changed/unknown payment snapshot cannot prove a cash history.
                $before = $this->cents($before);
                $after = $this->cents($after);
                if ($before === null || $after === null || $before !== $after
                    || ($balance !== null && $before !== $balance)) {
                    return null;
                }
                if ($balance === null) {
                    $balance = $before;
                    $opening = $before;
                }

                continue;
            }
            $before = $log->action === 'order.created_manually' ? 0 : $this->cents(data_get($p, 'old.paid_amount_cents'));
            $after = $this->cents(data_get($p, $log->action === 'order.created_manually' ? 'payment.paid_amount_cents' : 'new.paid_amount_cents'));
            if ($before === null || $after === null || ($balance !== null && $balance !== $before)) {
                return null;
            }
            if ($balance === null) {
                $opening = $before;
            }
            $balance = $after;
            $lastPaymentLog = $log;
            $delta = $after - $before;
            if ($delta === 0) {
                continue;
            }
            $event = new OrderPaymentEvent([
                'id' => -(int) $log->id, 'checkout_group_key' => $baseline->checkout_group_key,
                'order_id' => $baseline->order_id, 'actor_user_id' => $log->user_id,
                'event_type' => $delta > 0 ? 'payment_received' : 'payment_reversed',
                'source' => 'historical_admin_activity', 'previous_paid_amount_cents' => $before,
                'new_paid_amount_cents' => $after, 'amount_delta_cents' => $delta,
                'affects_collection_stats' => true, 'occurred_at' => $log->created_at,
                'new_status' => data_get($p, $log->action === 'order.created_manually' ? 'payment.payment_status' : 'new.payment_status', $baseline->new_status),
                'payment_method' => data_get($p, $log->action === 'order.created_manually' ? 'payment.payment_method' : 'new.payment_method'),
                'metadata' => ['historical_activity_log_id' => $log->id, 'baseline_event_id' => $baseline->id],
            ]);
            $event->setRelation('actor', $log->user);
            $events->push($event);
        }
        if ($balance === null) {
            return ['events' => collect(), 'opening_cents' => (int) $baseline->new_paid_amount_cents,
                'end_cents' => (int) $baseline->new_paid_amount_cents, 'last_payment_log' => null];
        }

        return ['events' => $events, 'opening_cents' => $opening, 'end_cents' => $balance, 'last_payment_log' => $lastPaymentLog];
    }

    /** Correct only the proven MIN(id)-of-a-deleted-carrier migration mistake. No writes. */
    private function deletedCarrierCorrection(OrderPaymentEvent $baseline, array $page, Collection $orders, Collection $laterStates): ?array
    {
        $snapshot = (int) $baseline->new_paid_amount_cents;
        $last = $page['last_payment_log'];
        $original = $orders->firstWhere('id', $baseline->order_id);
        if (! $last || $page['events']->isEmpty() || $page['opening_cents'] !== $snapshot
            || ! $original || ! $original->deleted_at
            || ! $original->created_at->lte($baseline->created_at)
            || ! $original->deleted_at->lt($last->created_at)
            || ! $last->created_at->lt($baseline->created_at)
            || ! $baseline->occurred_at->equalTo($last->created_at)
            || (int) $original->paid_amount_cents !== $snapshot
            || ($original->payment_updated_at && $original->payment_updated_at->gt($original->deleted_at))) {
            return null;
        }
        $atCapture = $orders->filter(fn (Order $order): bool => $order->created_at->lte($baseline->created_at));
        if ((int) $atCapture->min('id') !== (int) $original->id) {
            return null;
        }
        $active = $atCapture->filter(fn (Order $order): bool => ! $order->deleted_at || $order->deleted_at->gt($baseline->created_at));
        if ($active->isEmpty()) {
            return null;
        }
        $retained = ! $active->contains(fn (Order $order): bool => (int) $order->paid_amount_cents !== $page['end_cents'] || ! $order->payment_updated_at
            || ! $order->payment_updated_at->equalTo($last->created_at));
        // A later immutable transition's BEFORE snapshot can independently anchor
        // the old end balance, so a legitimate new payment does not erase history.
        // Never skip an earlier conflicting transition to find a matching one.
        $anchor = $laterStates->first(fn (OrderPaymentEvent $event): bool => $event->created_at->gte($baseline->created_at));
        $anchored = $anchor && $anchor->occurred_at->gte($baseline->created_at)
            && in_array($anchor->event_type, ['payment_received', 'payment_reversed', 'payment_status_changed', 'payment_balance_adjusted', 'discount_adjustment'], true)
            && $active->contains('id', $anchor->order_id)
            && (int) $anchor->previous_paid_amount_cents === $page['end_cents'];
        if (! $retained && ! $anchored) {
            return null;
        }

        return ['key' => $baseline->checkout_group_key, 'baseline_event_id' => $baseline->id,
            'discarded_order_id' => $original->id, 'order_id' => $active->first()->id,
            'snapshot_cents' => $snapshot, 'corrected_cents' => $page['end_cents'],
            'delta_cents' => $page['end_cents'] - $snapshot,
            'proof' => $retained ? 'retained_payment_state' : 'post_baseline_ledger',
            'post_baseline_anchor_event_id' => ! $retained && $anchored ? $anchor->id : null,
            'last_payment_log_id' => $last->id, 'last_payment_log_at' => $last->created_at->toISOString()];
    }

    private function cents(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,15}$/D', $value))) {
            return null;
        }

        return $value >= 0 ? (int) $value : null;
    }
}
