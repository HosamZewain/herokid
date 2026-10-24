<?php

namespace App\Services\Orders;

use Illuminate\Support\Collection;

/** Shared purchase values and physical quantities for daily and monthly cards. */
class DashboardCheckoutFacts
{
    public function forKeys($keys): Collection
    {
        return app(OrderFinancialStatistics::class)->checkouts($keys, true)->get()
            ->each(function ($fact): void {
                $fact->story_pieces = (int) $fact->story_quantity;
                $fact->product_pieces = (int) $fact->products;
                $fact->category = match (true) {
                    $fact->story_pieces > 0 && $fact->product_pieces > 0 => 'mixed',
                    $fact->story_pieces > 0 => 'story',
                    $fact->product_pieces > 0 => 'product',
                    default => 'unclassified',
                };
            });
    }
}
