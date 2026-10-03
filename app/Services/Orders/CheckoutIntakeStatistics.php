<?php

namespace App\Services\Orders;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** A purchase belongs to its first creation date, including deleted history. */
class CheckoutIntakeStatistics
{
    public function query(): Builder
    {
        return DB::table('orders')
            ->whereNotNull('checkout_group_key')
            ->select('checkout_group_key')
            ->selectRaw('MIN(created_at) as first_created_at')
            ->groupBy('checkout_group_key');
    }

    public function keysBetween(mixed $start = null, mixed $end = null): Builder
    {
        $query = $this->query();
        if ($start !== null) {
            $query->havingRaw('MIN(created_at) >= ?', [$start]);
        }
        if ($end !== null) {
            $query->havingRaw('MIN(created_at) <= ?', [$end]);
        }

        return $query->select('checkout_group_key');
    }

    public function countBetween(mixed $start, mixed $end): int
    {
        return DB::query()->fromSub($this->keysBetween($start, $end), 'purchases')->count();
    }

    /** Original dates for a bounded set of purchases, not all order models. */
    public function datesForKeys(Collection $keys): Collection
    {
        return $keys->isEmpty() ? collect() : $this->query()
            ->whereIn('checkout_group_key', $keys)
            ->pluck('first_created_at', 'checkout_group_key');
    }
}
