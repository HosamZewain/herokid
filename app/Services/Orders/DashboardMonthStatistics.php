<?php

namespace App\Services\Orders;

use App\Services\Payments\PaymentCollectionReportService;
use App\Support\OrderDateTime;
use Carbon\CarbonImmutable;

class DashboardMonthStatistics
{
    public function summarize(CarbonImmutable $today): array
    {
        $from = $today->startOfMonth()->toDateString();
        $to = $today->toDateString();
        $start = OrderDateTime::utcStartOfDay($from);
        $end = OrderDateTime::utcEndOfDay($to);
        $keys = app(CheckoutIntakeStatistics::class)->keysBetween($start, $end);
        $facts = app(DashboardCheckoutFacts::class)->forKeys($keys);
        $stories = $facts->where('category', 'story');
        $products = $facts->where('category', 'product');
        $mixed = $facts->where('category', 'mixed');
        $cash = app(PaymentCollectionReportService::class)->movementsBetween($start, $end);

        return [
            'from' => $from, 'to' => $to, 'label' => $today->translatedFormat('F Y'),
            'elapsed_days' => $today->day, 'checkouts' => $facts->count(),
            'story_checkouts' => $stories->count(), 'product_checkouts' => $products->count(),
            'mixed_checkouts' => $mixed->count(),
            'unclassified_checkouts' => $facts->count() - $stories->count() - $products->count() - $mixed->count(),
            'story_pieces' => (int) $facts->sum('story_pieces'), 'product_pieces' => (int) $facts->sum('product_pieces'),
            'pieces' => (int) $facts->sum('story_pieces') + (int) $facts->sum('product_pieces'),
            'story_only_pieces' => (int) $stories->sum('story_pieces'),
            'product_only_pieces' => (int) $products->sum('product_pieces'),
            'mixed_pieces' => (int) $mixed->sum(fn ($f) => $f->story_pieces + $f->product_pieces),
            'daily_average' => round($facts->count() / $today->day, 2),
            'value_cents' => (int) $facts->sum('total_cents'),
            'paid_balance_cents' => (int) $facts->sum('paid_amount_cents'),
            'outstanding_cents' => (int) $facts->sum(fn ($f) => max(0, $f->total_cents - $f->paid_amount_cents)),
            'excess_paid_cents' => (int) $facts->sum(fn ($f) => max(0, $f->paid_amount_cents - $f->total_cents)),
            'cancelled_checkouts' => $facts->where('all_cancelled', 1)->count(),
            'payments_cents' => (int) $cash->sum('amount_delta_cents'),
        ];
    }
}
