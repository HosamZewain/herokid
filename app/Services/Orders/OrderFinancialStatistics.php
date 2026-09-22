<?php

namespace App\Services\Orders;

use App\Support\OrderPaymentStatus;
use App\Support\OrderStatusRegistry;
use App\Support\OrderWorkflowStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Checkout-level aggregates. No order models or full story text are hydrated. */
class OrderFinancialStatistics
{
    public function checkouts($keys, bool $includeDeleted, bool $preferActive = true): Builder
    {
        $orders = DB::table('orders as o')->whereIn('o.checkout_group_key', $keys);
        if (! $includeDeleted) {
            $orders->whereNull('o.deleted_at');
        } elseif ($preferActive) {
            $orders->where(fn ($q) => $q->whereNull('o.deleted_at')->orWhereNotExists(fn ($live) => $live
                ->selectRaw('1')->from('orders as live')
                ->whereColumn('live.checkout_group_key', 'o.checkout_group_key')->whereNull('live.deleted_at')));
        }

        $items = DB::table('order_items')->select('order_id')
            ->whereIn('order_id', (clone $orders)->select('o.id'))
            ->selectRaw('SUM(total_price_cents) as cents, MAX(CASE WHEN item_type = ? THEN 1 ELSE 0 END) as has_story, SUM(CASE WHEN item_type IN (?, ?) THEN quantity ELSE 0 END) as products', ['story', 'product', 'product_add_on'])
            ->groupBy('order_id');
        $grammar = DB::connection()->getQueryGrammar();
        $itemPrice = $grammar->wrap('o.delivery_details->item_price');
        $delivery = $grammar->wrap('first_order.delivery_details->delivery_fee');
        $shippingKeys = OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_SHIPPING, false);
        $shippingSlots = implode(',', array_fill(0, count($shippingKeys), '?'));
        $shipping = "CASE WHEN o.shipping_status IN ($shippingSlots) THEN o.shipping_status ELSE ? END";
        $groups = $orders->leftJoinSub($items, 'i', 'i.order_id', '=', 'o.id')
            ->leftJoin('stories as s', 's.id', '=', 'o.story_id')
            ->groupBy('o.checkout_group_key')
            ->select('o.checkout_group_key')
            ->selectRaw('MIN(o.id) as first_id, COALESCE(SUM(i.cents), 0) as item_cents, COALESCE(MAX(o.discount_cents), 0) as discount_cents')
            ->selectRaw("ROUND(SUM(COALESCE(CAST(NULLIF($itemPrice, 'null') AS DECIMAL(18,4)), s.price, 0)) * 100) as legacy_cents")
            ->selectRaw('SUM(CASE WHEN o.story_id IS NOT NULL OR i.has_story = 1 THEN 1 ELSE 0 END) as stories, COALESCE(SUM(i.products), 0) as products')
            ->selectRaw("CASE WHEN COUNT(DISTINCT NULLIF(NULLIF(o.status, ''), '0')) = 1 THEN MAX(NULLIF(NULLIF(o.status, ''), '0')) ELSE 'mixed' END as status")
            ->selectRaw("CASE WHEN COUNT(DISTINCT $shipping) = 1 THEN MAX($shipping) ELSE 'mixed' END as shipping_status", [...$shippingKeys, OrderWorkflowStatus::SHIPPING_NOT_READY, ...$shippingKeys, OrderWorkflowStatus::SHIPPING_NOT_READY]);

        $paymentKeys = OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_PAYMENT, false);
        $slots = implode(',', array_fill(0, count($paymentKeys), '?'));
        $base = DB::query()->fromSub($groups, 'g')->join('orders as first_order', 'first_order.id', '=', 'g.first_id')
            ->select('g.*')
            ->selectRaw("CASE WHEN first_order.payment_status IN ($slots) THEN first_order.payment_status ELSE ? END as payment_status", [...$paymentKeys, OrderPaymentStatus::UNPAID])
            ->selectRaw('COALESCE(first_order.paid_amount_cents, 0) as raw_paid')
            ->selectRaw('(CASE WHEN item_cents = 0 THEN legacy_cents ELSE item_cents END - g.discount_cents) as raw_average_value')
            ->selectRaw("(CASE WHEN item_cents = 0 THEN legacy_cents ELSE item_cents END + ROUND(CASE WHEN CAST(COALESCE(NULLIF($delivery, 'null'), '0') AS DECIMAL(18,4)) > 0 THEN CAST($delivery AS DECIMAL(18,4)) ELSE 0 END * 100) - g.discount_cents) as raw_total");
        $totals = DB::query()->fromSub($base, 'b')
            ->select('b.*')
            ->selectRaw('CASE WHEN raw_average_value > 0 THEN raw_average_value ELSE 0 END as average_value_cents')
            ->selectRaw('CASE WHEN raw_total > 0 THEN raw_total ELSE 0 END as total_cents');

        return DB::query()->fromSub($totals, 't')->select('t.*')
            ->selectRaw('CASE WHEN raw_paid < 0 THEN 0 ELSE raw_paid END as paid_amount_cents');
    }

    public function summarize($keys, bool $includeDeleted, bool $preferActive = true): array
    {
        $query = DB::query()->fromSub($this->checkouts($keys, $includeDeleted, $preferActive), 'c')
            ->selectRaw('COUNT(*) as checkouts, COALESCE(SUM(stories), 0) as stories, COALESCE(SUM(products), 0) as products, COALESCE(SUM(total_cents), 0) as total_value_cents, COALESCE(ROUND(AVG(average_value_cents)), 0) as average_order_cents, COALESCE(SUM(paid_amount_cents), 0) as collected_cents, COALESCE(SUM(CASE WHEN total_cents > paid_amount_cents THEN total_cents - paid_amount_cents ELSE 0 END), 0) as outstanding_cents, COALESCE(SUM(CASE WHEN paid_amount_cents > 0 THEN 1 ELSE 0 END), 0) as payment_checkouts');
        foreach ([
            ['status', OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'cancelled'), 'cancelled'],
            ['payment_status', OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_PAYMENT, 'paid_in_full'), 'paid'],
            ['shipping_status', array_merge(OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'shipped'), OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'delivered')), 'shipped'],
        ] as [$column, $statuses, $prefix]) {
            $condition = $statuses ? "$column IN (".implode(',', array_fill(0, count($statuses), '?')).')' : '1 = 0';
            $query->selectRaw("COALESCE(SUM(CASE WHEN $condition THEN 1 ELSE 0 END), 0) as {$prefix}_checkouts", $statuses);
            if ($prefix !== 'shipped') {
                $query->selectRaw("COALESCE(SUM(CASE WHEN $condition THEN total_cents ELSE 0 END), 0) as {$prefix}_value_cents", $statuses);
            }
        }

        return array_map(fn ($value) => (int) $value, (array) $query->first());
    }
}
