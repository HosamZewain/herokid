<?php

namespace App\Services\Pricing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PackageAnalyticsService
{
    /**
     * Count each purchased package instance once, even when it produced several
     * story orders and product order items.
     *
     * @param  Collection<int, int>  $packageIds
     * @return array<int, int>
     */
    public function purchaseCounts(Collection $packageIds): array
    {
        $packageIds = $packageIds->map(fn ($id): int => (int) $id)->unique()->values();
        if ($packageIds->isEmpty()) {
            return [];
        }

        $instances = [];
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('order_items.item_snapshot')
            ->get([
                'order_items.id',
                'order_items.order_id',
                'order_items.item_snapshot',
                'orders.checkout_group_key',
            ]);

        foreach ($rows as $row) {
            $snapshot = is_array($row->item_snapshot)
                ? $row->item_snapshot
                : json_decode((string) $row->item_snapshot, true);
            $packageId = (int) data_get($snapshot, 'package.id', 0);
            if (! $packageIds->containsStrict($packageId)) {
                continue;
            }

            $instanceKey = data_get($snapshot, 'package.instance_key')
                ?: $row->checkout_group_key
                ?: 'order-'.$row->order_id;
            $instances[$packageId][(string) $instanceKey] = true;
        }

        return $packageIds->mapWithKeys(fn (int $packageId): array => [
            $packageId => count($instances[$packageId] ?? []),
        ])->all();
    }
}
