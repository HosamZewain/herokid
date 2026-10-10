<?php

namespace App\Services\Payments;

use App\Models\AdminActivityLog;
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
            foreach ($batch as $baseline) {
                $key = $baseline->checkout_group_key;
                $same = $logs->get($key, collect())->filter(fn ($log) => $log->created_at->lt($baseline->created_at));
                $duplicate = $baselineCounts->get($key, 0) > 1;
                $overlap = $alreadyDated->get($key, collect())->contains(fn ($event) => $event->occurred_at->lte($baseline->created_at));
                $projected = $duplicate || $overlap ? null : $this->project($baseline, $same);
                if ($projected === null) {
                    $issues->push(['key' => $key, 'order_id' => $baseline->order_id,
                        'reason' => $duplicate ? 'duplicate_baseline' : ($overlap ? 'overlapping_ledger' : 'incomplete_history')]);
                    $undated->push(['key' => $key, 'cents' => (int) $baseline->new_paid_amount_cents]);

                    continue;
                }
                $events = $events->concat($projected['events']);
                $undated->push(['key' => $key, 'cents' => $projected['opening_cents']]);
            }
        }

        return $this->result = ['events' => $events->values(), 'undated' => $undated,
            'baseline_cents' => (int) $baselines->sum('new_paid_amount_cents'),
            'recovered_net_cents' => (int) $events->sum('amount_delta_cents'),
            'undated_cents' => (int) $undated->sum('cents'), 'issues' => $issues,
            'baseline_captured_at' => $baselines->min('created_at')];
    }

    private function project(OrderPaymentEvent $baseline, Collection $logs): ?array
    {
        $balance = null;
        $opening = 0;
        $events = collect();
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
            return ['events' => collect(), 'opening_cents' => (int) $baseline->new_paid_amount_cents];
        }
        if ($balance !== (int) $baseline->new_paid_amount_cents) {
            return null;
        }

        return ['events' => $events, 'opening_cents' => $opening];
    }

    private function cents(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,15}$/D', $value))) {
            return null;
        }

        return $value >= 0 ? (int) $value : null;
    }
}
