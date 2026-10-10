<?php

namespace App\Services\Payments;

use App\Models\OrderGroupMergeAlias;
use App\Models\OrderPaymentEvent;
use Illuminate\Support\Facades\DB;

/** Explain balances, not a bank/cash statement. No automatic balance repair. */
class PaymentReconciliationService
{
    private ?array $aliases = null;

    public function canonicalKey(string $key): string
    {
        $this->aliases ??= OrderGroupMergeAlias::query()->pluck('target_checkout_group_key', 'source_checkout_group_key')->all();
        $seen = [];
        while (isset($this->aliases[$key])) {
            if (isset($seen[$key])) {
                throw new \RuntimeException('Payment reconciliation stopped: checkout merge alias cycle.');
            }
            $seen[$key] = true;
            $key = $this->aliases[$key];
        }

        return $key;
    }

    public function report(): array
    {
        $events = OrderPaymentEvent::query()->get(['checkout_group_key', 'event_type', 'affects_collection_stats',
            'new_paid_amount_cents', 'previous_paid_amount_cents', 'amount_delta_cents', 'occurred_at']);
        $expected = [];
        $opening = $cash = $adjustments = $transferred = 0;
        foreach ($events as $event) {
            if ($event->event_type === 'legacy_baseline') {
                $delta = (int) $event->new_paid_amount_cents;
                $opening += $delta;
            } elseif ($event->affects_collection_stats) {
                $delta = (int) $event->amount_delta_cents;
                $cash += $delta;
            } elseif ($event->event_type === 'merge_reconciliation') {
                $transferred += (int) $event->new_paid_amount_cents - (int) $event->previous_paid_amount_cents;

                continue; // Source receipts already exist; a merge is not new money.
            } else {
                $delta = (int) $event->new_paid_amount_cents - (int) $event->previous_paid_amount_cents;
                $adjustments += $delta;
            }
            $key = $this->canonicalKey($event->checkout_group_key);
            $expected[$key] = ($expected[$key] ?? 0) + $delta;
        }
        $representatives = DB::table('orders as o')->select('o.checkout_group_key')->selectRaw('MIN(o.id) as first_id')
            ->whereNotNull('o.checkout_group_key')->where('o.checkout_group_key', '!=', '')
            ->where(fn ($q) => $q->whereNull('o.deleted_at')->orWhereNotExists(fn ($live) => $live
                ->selectRaw('1')->from('orders as live')->whereColumn('live.checkout_group_key', 'o.checkout_group_key')->whereNull('live.deleted_at')))
            ->groupBy('o.checkout_group_key');
        $current = DB::query()->fromSub($representatives, 'g')->join('orders as first', 'first.id', '=', 'g.first_id')
            ->leftJoin('order_checkout_references as ref', 'ref.checkout_group_key', '=', 'g.checkout_group_key')
            ->get(['g.checkout_group_key', 'g.first_id', 'first.paid_amount_cents', 'first.deleted_at', 'ref.short_reference'])
            ->keyBy('checkout_group_key');
        $mergedCopies = $current->filter(fn ($row, string $key): bool => $this->canonicalKey($key) !== $key);
        $current = $current->reject(fn ($row, string $key): bool => $this->canonicalKey($key) !== $key);
        $differences = collect(array_unique([...array_keys($expected), ...$current->keys()->all()]))->map(function (string $key) use ($expected, $current): array {
            $row = $current->get($key);
            $paid = max(0, (int) ($row->paid_amount_cents ?? 0));
            $balance = (int) ($expected[$key] ?? 0);

            return ['key' => $key, 'order_id' => $row->first_id ?? null, 'reference' => $row->short_reference ?? $key,
                'deleted' => $row ? $row->deleted_at !== null : false, 'missing_order' => $row === null,
                'merged_source' => $this->canonicalKey($key) !== $key, 'current_cents' => $paid,
                'expected_cents' => $balance, 'difference_cents' => $paid - $balance];
        })->filter(fn (array $row): bool => $row['difference_cents'] !== 0)
            ->sortByDesc(fn (array $row): int => abs($row['difference_cents']))->values();
        $history = app(HistoricalPaymentSource::class)->history();

        return ['opening_cents' => $opening, 'recorded_net_cents' => $cash,
            'opening_plus_net_cents' => $opening + $cash, 'non_cash_adjustments_cents' => $adjustments,
            'merge_transfers_cents' => $transferred, 'expected_balance_cents' => array_sum($expected),
            'merged_source_copies_cents' => (int) $mergedCopies->sum('paid_amount_cents'),
            'current_balance_cents' => (int) $current->sum('paid_amount_cents'),
            'unreconciled_cents' => (int) $differences->sum('difference_cents'), 'differences' => $differences,
            'history' => $history, 'first_ledger_collection_at' => $events->where('affects_collection_stats', true)->min('occurred_at')];
    }
}
