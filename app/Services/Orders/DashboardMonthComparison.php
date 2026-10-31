<?php

namespace App\Services\Orders;

use App\Support\OrderDateTime;
use Carbon\CarbonImmutable;

class DashboardMonthComparison
{
    public function summarize(CarbonImmutable $now): array
    {
        $now = $now->setTimezone('Africa/Cairo');
        $first = $now->startOfMonth()->subMonths(5);
        $months = [];
        for ($offset = 0; $offset < 6; $offset++) {
            $month = $first->addMonths($offset);
            $months[$month->format('Y-m')] = ['key' => $month->format('Y-m'), 'label' => $month->locale('ar')->translatedFormat('F Y'),
                'short_label' => $month->locale('ar')->translatedFormat('F'), 'year' => $month->year,
                'partial' => $offset === 5, 'checkouts' => 0, 'story_pieces' => 0, 'product_pieces' => 0,
                'shipped_checkouts' => 0, 'net_value_cents' => 0, 'average_order_cents' => null];
        }
        $intake = app(CheckoutIntakeStatistics::class);
        $keys = $intake->keysBetween(OrderDateTime::utcStartOfDay($first->toDateString()), $now->utc());
        // One bounded facts query, not six dashboard/ledger queries or one query per order.
        $facts = app(DashboardCheckoutFacts::class)->forKeys($keys);
        $dates = $intake->datesForKeys($facts->pluck('checkout_group_key'));
        foreach ($facts as $fact) {
            $key = CarbonImmutable::parse($dates[$fact->checkout_group_key], 'UTC')->setTimezone('Africa/Cairo')->format('Y-m');
            if (! isset($months[$key])) {
                continue;
            }
            $months[$key]['checkouts']++;
            $months[$key]['story_pieces'] += (int) $fact->story_pieces;
            $months[$key]['product_pieces'] += (int) $fact->product_pieces;
            $months[$key]['net_value_cents'] += (int) $fact->average_value_cents;
        }
        // Dispatch is a separate date cohort and can include much older purchases.
        $shipping = app(AdminShippingReportService::class)->dispatchDatesBetween(OrderDateTime::utcStartOfDay($first->toDateString()), $now->utc());
        foreach ($shipping['dates'] as $date) {
            $key = CarbonImmutable::parse($date, 'UTC')->setTimezone('Africa/Cairo')->format('Y-m');
            $months[$key]['shipped_checkouts']++;
        }
        foreach ($months as &$month) {
            $month['average_order_cents'] = $month['checkouts'] ? (int) round($month['net_value_cents'] / $month['checkouts']) : null;
            unset($month['net_value_cents']);
        }
        unset($month);

        return ['months' => array_values($months), 'undated_shipments' => $shipping['undated']];
    }
}
