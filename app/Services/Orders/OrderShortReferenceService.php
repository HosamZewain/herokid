<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderCheckoutReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderShortReferenceService
{
    public function ensureForOrder(Order $order): OrderCheckoutReference
    {
        return $this->ensure(
            $order->checkoutGroupKey(),
            CarbonImmutable::instance($order->created_at ?? now()),
        );
    }

    public function ensure(string $checkoutGroupKey, CarbonImmutable $createdAt): OrderCheckoutReference
    {
        $existing = OrderCheckoutReference::query()
            ->where('checkout_group_key', $checkoutGroupKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($checkoutGroupKey, $createdAt): OrderCheckoutReference {
                $existing = OrderCheckoutReference::query()
                    ->where('checkout_group_key', $checkoutGroupKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $month = $createdAt->format('m');
                $now = now();

                DB::table('order_checkout_reference_counters')->insertOrIgnore([
                    'month_key' => $month,
                    'last_sequence' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $counter = DB::table('order_checkout_reference_counters')
                    ->where('month_key', $month)
                    ->lockForUpdate()
                    ->first();
                $sequence = ((int) $counter->last_sequence) + 1;

                DB::table('order_checkout_reference_counters')
                    ->where('month_key', $month)
                    ->update([
                        'last_sequence' => $sequence,
                        'updated_at' => $now,
                    ]);

                return OrderCheckoutReference::query()->create([
                    'checkout_group_key' => $checkoutGroupKey,
                    'reference_month' => (int) $month,
                    'monthly_sequence' => $sequence,
                    'short_reference' => 'HK'.$month.'-'.$sequence,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $existing = OrderCheckoutReference::query()
                ->where('checkout_group_key', $checkoutGroupKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }
}
