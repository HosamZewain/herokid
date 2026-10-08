<?php

namespace App\Services\Orders;

use App\Support\AppDateTime;
use App\Support\OrderStatusRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminShippingReportService
{
    public function report(Request $request): array
    {
        $today = AppDateTime::display(now())->toDateString();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'day' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $from = $filters['from'] ?? CarbonImmutable::parse($today)->subDays(29)->toDateString();
        $to = $filters['to'] ?? $today;
        abort_if($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 365, 422, 'اختر فترة صحيحة لا تتجاوز سنة.');
        $day = $filters['day'] ?? $to;
        abort_if($day < $from || $day > $to, 422, 'اليوم المختار يجب أن يكون داخل فترة التقرير.');

        $base = $this->factsQuery();
        $facts = DB::query()->fromSub($base, 'shipments')
            ->whereBetween('shipped_at', [AppDateTime::utcStartOfDay($from), AppDateTime::utcEndOfDay($to)])
            ->orderByDesc('shipped_at')->orderByDesc('representative_id')->get();
        $facts->each(function ($row): void {
            $row->day = AppDateTime::format($row->shipped_at, 'Y-m-d');
        });
        $daily = $facts->groupBy('day')->map(fn (Collection $rows, string $date): array => [
            'day' => $date, ...$this->totals($rows),
        ]);
        // Include zero days, so a missing day is not mistaken for missing data.
        for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $to; $date = $date->addDay()) {
            $key = $date->toDateString();
            if (! $daily->has($key)) {
                $daily->put($key, ['day' => $key, ...$this->totals(collect())]);
            }
        }
        $selected = $facts->where('day', $day)->values();
        $items = $this->items($selected->pluck('key'));
        $teams = $selected->groupBy(fn ($row) => $row->assignee_id ?? 'unassigned')->map(function (Collection $rows) use ($items): array {
            $products = $rows->flatMap(fn ($row) => $items->get($row->key, collect()));

            return ['name' => $rows->first()->assignee_name ?? 'غير مسند', ...$this->totals($rows),
                'contents' => $products->groupBy(fn ($item) => $item->item_type.':'.($item->product_id ?? $item->story_id ?? '').':'.$item->title)
                    ->map(fn (Collection $entries): array => ['title' => $entries->first()->title, 'type' => $entries->first()->item_type, 'quantity' => (int) $entries->sum('quantity')])->values()];
        })->sortBy('name')->values();
        $page = (int) ($filters['page'] ?? 1);
        $pageRows = $selected->forPage($page, 25)->values()->map(function ($row) use ($items): array {
            return (array) $row + ['items' => $items->get($row->key, collect())];
        });
        $rows = new LengthAwarePaginator($pageRows, $selected->count(), 25, $page, ['path' => $request->url(), 'query' => $request->except('page')]);

        return compact('from', 'to', 'day', 'teams', 'rows') + [
            'daily' => $daily->sortKeysDesc()->values(), 'summary' => $this->totals($facts),
            'selected_summary' => $this->totals($selected),
            'undated' => DB::query()->fromSub($this->factsQuery(), 'shipments')->whereNull('shipped_at')->where('has_shipping_progress', '>', 0)->count(),
        ];
    }

    private function totals(Collection $rows): array
    {
        return ['shipments' => $rows->count(), 'products' => (int) $rows->sum('product_quantity'),
            'stories' => (int) $rows->sum('story_quantity'), 'add_ons' => (int) $rows->sum('add_on_quantity'),
            'items' => (int) $rows->sum('product_quantity') + (int) $rows->sum('story_quantity') + (int) $rows->sum('add_on_quantity')];
    }

    private function groupKey(string $alias): string
    {
        return "COALESCE(NULLIF({$alias}.checkout_group_key, ''), CONCAT('order:', {$alias}.id))";
    }

    private function factsQuery(): Builder
    {
        $shipped = array_unique(array_merge(['shipped'], OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'shipped', false)));
        $progress = array_unique(array_merge($shipped, ['delivered', 'returned'],
            OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'delivered', false),
            OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'returned', false)));
        $logs = DB::table('order_status_logs as l')->join('orders as historical', 'historical.id', '=', 'l.order_id')
            ->where('l.status_type', 'shipping')->whereIn('l.status', $shipped)
            ->selectRaw($this->groupKey('historical').' as checkout_key, MIN(l.created_at) as shipped_at')->groupByRaw($this->groupKey('historical'));
        // These are the same dispatched/in-transit states used by BostaWebhookService.
        // Delivered/returned/cancelled events alone do not establish a dispatch date.
        $events = DB::table('bosta_shipment_events')->whereIn('state_code', [21, 22, 23, 24, 25, 30, 40, 41])
            ->whereNotNull('occurred_at')->selectRaw('bosta_shipment_id, MIN(occurred_at) as shipped_at')->groupBy('bosta_shipment_id');
        $itemCounts = DB::table('order_items')->selectRaw("order_id, COUNT(*) as item_count,
            SUM(CASE WHEN item_type = 'product' THEN quantity ELSE 0 END) as product_quantity,
            SUM(CASE WHEN item_type = 'story' THEN quantity ELSE 0 END) as story_quantity,
            SUM(CASE WHEN item_type NOT IN ('product', 'story') THEN quantity ELSE 0 END) as add_on_quantity")
            ->groupBy('order_id');
        $placeholders = implode(',', array_fill(0, count($progress), '?'));
        $groups = DB::table('orders as o')->whereNull('o.deleted_at')->leftJoinSub($itemCounts, 'ic', 'ic.order_id', '=', 'o.id')
            ->selectRaw($this->groupKey('o').' as checkout_key, MIN(o.id) as representative_id,
                SUM(COALESCE(ic.product_quantity, 0)) as product_quantity,
                SUM(CASE WHEN COALESCE(ic.item_count, 0) = 0 AND o.story_id IS NOT NULL THEN 1 ELSE COALESCE(ic.story_quantity, 0) END) as story_quantity,
                SUM(COALESCE(ic.add_on_quantity, 0)) as add_on_quantity')
            ->selectRaw("MAX(CASE WHEN o.shipping_status IN ({$placeholders}) THEN 1 ELSE 0 END) as has_shipping_progress", $progress)
            ->groupByRaw($this->groupKey('o'));

        return DB::query()->fromSub($groups, 'g')
            ->leftJoinSub($logs, 'logs', 'logs.checkout_key', '=', 'g.checkout_key')
            ->leftJoin('bosta_shipments as s', fn ($join) => $join->on('s.checkout_group_key', '=', 'g.checkout_key')->whereNull('s.deleted_at'))
            ->leftJoinSub($events, 'events', 'events.bosta_shipment_id', '=', 's.id')
            ->leftJoin('order_group_assignments as a', 'a.checkout_group_key', '=', 'g.checkout_key')
            ->leftJoin('users as u', 'u.id', '=', 'a.assigned_to_user_id')
            ->leftJoin('order_checkout_references as r', 'r.checkout_group_key', '=', 'g.checkout_key')
            ->selectRaw("g.*, g.checkout_key as `key`, COALESCE(events.shipped_at, logs.shipped_at) as shipped_at,
                CASE WHEN events.shipped_at IS NOT NULL THEN 'bosta' ELSE 'order_log' END as date_source,
                s.tracking_number, s.shipping_status as carrier_status, r.short_reference,
                a.assigned_to_user_id as assignee_id, u.name as assignee_name");
    }

    private function items(Collection $keys): Collection
    {
        $items = collect();
        foreach ($keys->chunk(200) as $batch) {
            $query = DB::table('orders as o')->whereNull('o.deleted_at')->whereIn(DB::raw($this->groupKey('o')), $batch)
                ->leftJoin('order_items as i', 'i.order_id', '=', 'o.id')->leftJoin('stories as story', 'story.id', '=', 'o.story_id')
                ->selectRaw($this->groupKey('o')." as checkout_key, o.id as order_id,
                    COALESCE(i.item_type, 'story') as item_type, i.product_id, COALESCE(i.story_id, o.story_id) as story_id,
                    COALESCE(i.title, story.title, 'قصة') as title, COALESCE(i.quantity, 1) as quantity")
                ->where(fn ($q) => $q->whereNotNull('i.id')->orWhereNotNull('o.story_id'))->orderBy('o.id')->orderBy('i.id')->get();
            $items = $items->concat($query);
        }

        return $items->groupBy('checkout_key');
    }
}
