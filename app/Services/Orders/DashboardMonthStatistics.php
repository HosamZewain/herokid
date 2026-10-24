<?php

namespace App\Services\Orders;

use App\Services\Payments\PaymentCollectionReportService;
use App\Support\OrderDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardMonthStatistics
{
    public function summarize(CarbonImmutable $today): array
    {
        $from = $today->startOfMonth()->toDateString();
        $to = $today->toDateString();
        $start = OrderDateTime::utcStartOfDay($from);
        $end = OrderDateTime::utcEndOfDay($to);
        $keys = app(CheckoutIntakeStatistics::class)->keysBetween($start, $end);
        $financial = app(OrderFinancialStatistics::class)->checkouts($keys, true);

        // Prefer live replacement rows, retaining wholly deleted purchases as
        // history. Merged source copies are already excluded by financial facts.
        $orders = DB::table('orders as o')
            ->whereIn('o.checkout_group_key', (clone $financial)->select('t.checkout_group_key'))
            ->where(fn ($q) => $q->whereNull('o.deleted_at')->orWhereNotExists(fn ($live) => $live
                ->selectRaw('1')->from('orders as live')
                ->whereColumn('live.checkout_group_key', 'o.checkout_group_key')->whereNull('live.deleted_at')));
        $items = DB::table('order_items')->select('order_id')
            ->whereIn('order_id', (clone $orders)->select('o.id'))
            ->selectRaw("SUM(CASE WHEN item_type = 'story' THEN quantity ELSE 0 END) as stories")
            ->selectRaw("SUM(CASE WHEN item_type IN ('product', 'product_add_on') THEN quantity ELSE 0 END) as products")
            ->groupBy('order_id');
        $quantities = $orders->leftJoinSub($items, 'i', 'i.order_id', '=', 'o.id')
            ->select('o.checkout_group_key')->groupBy('o.checkout_group_key')
            ->selectRaw('SUM(CASE WHEN COALESCE(i.stories, 0) > 0 THEN i.stories WHEN o.story_id IS NOT NULL THEN 1 ELSE 0 END) as story_pieces')
            ->selectRaw('COALESCE(SUM(i.products), 0) as product_pieces');
        $facts = DB::query()->fromSub($financial, 'f')
            ->joinSub($quantities, 'q', 'q.checkout_group_key', '=', 'f.checkout_group_key')
            ->select('f.*', 'q.story_pieces', 'q.product_pieces')->get();
        $stories = $facts->filter(fn ($f) => $f->story_pieces > 0 && $f->product_pieces == 0);
        $products = $facts->filter(fn ($f) => $f->product_pieces > 0 && $f->story_pieces == 0);
        $mixed = $facts->filter(fn ($f) => $f->story_pieces > 0 && $f->product_pieces > 0);
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
            'cancelled_checkouts' => $facts->where('all_cancelled', 1)->count(),
            'payments_cents' => (int) $cash->sum('amount_delta_cents'),
        ];
    }
}
