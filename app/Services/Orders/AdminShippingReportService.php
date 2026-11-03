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
    /** Read-only aggregate evidence for the permission-guarded dashboard. */
    public function dispatchDatesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = DB::query()->fromSub($this->factsQuery(), 'shipments')->where('has_shipping_progress', 1);

        return [
            'dates' => (clone $base)->whereBetween('shipped_at', [$from, $to])
                ->select('key')->selectRaw('MIN(shipped_at) as shipped_at')->groupBy('key')->pluck('shipped_at', 'key'),
            'undated' => (clone $base)->whereNull('shipped_at')->distinct()->count('key'),
        ];
    }

    /** @param list<int>|null $assigneeIds Trusted caller scope; null preserves the shipping report. */
    public function report(Request $request, ?array $assigneeIds = null): array
    {
        $today = AppDateTime::display(now())->toDateString();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'day' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'employee' => ['nullable', 'string', 'regex:/^(unassigned|[1-9][0-9]*)$/'],
        ]);
        $from = $filters['from'] ?? CarbonImmutable::parse($today)->subDays(29)->toDateString();
        $to = $filters['to'] ?? $today;
        abort_if($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 365, 422, 'اختر فترة صحيحة لا تتجاوز سنة.');
        $day = $filters['day'] ?? null;
        $employee = $filters['employee'] ?? null;
        $canViewValues = ($request->user() ?? auth()->user())?->hasAnyPermission(['orders.statistics.view', 'order_reports.view']) ?? false;
        abort_if($day !== null && ($day < $from || $day > $to), 422, 'اليوم المختار يجب أن يكون داخل فترة التقرير.');

        $base = $this->factsQuery();
        if ($assigneeIds !== null) {
            $base->whereIn('a.assigned_to_user_id', $assigneeIds);
        }
        $facts = DB::query()->fromSub($base, 'shipments')
            ->where('has_shipping_progress', 1)
            ->whereBetween('shipped_at', [AppDateTime::utcStartOfDay($from), AppDateTime::utcEndOfDay($to)])
            ->orderByDesc('shipped_at')->orderByDesc('representative_id')->get();
        if ($canViewValues) {
            $this->addFinancials($facts);
        }
        $facts->each(function ($row): void {
            $row->day = AppDateTime::format($row->shipped_at, 'Y-m-d');
        });
        $items = $this->items($facts->pluck('key'), $canViewValues);
        if ($canViewValues) {
            $this->allocateCategoryValues($facts, $items);
        }
        $employeeFacts = $facts->filter(fn ($row) => $employee === null || (string) ($row->assignee_id ?? 'unassigned') === $employee)->values();
        $daily = $employeeFacts->groupBy('day')->map(fn (Collection $rows, string $date): array => [
            'day' => $date, ...$this->totals($rows), 'teams' => $this->teamTotals($rows),
        ]);
        // Include zero days, so a missing day is not mistaken for missing data.
        for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $to; $date = $date->addDay()) {
            $key = $date->toDateString();
            if (! $daily->has($key)) {
                $daily->put($key, ['day' => $key, ...$this->totals(collect()), 'teams' => collect()]);
            }
        }
        $selected = $facts->filter(fn ($row) => ($day === null || $row->day === $day)
            && ($employee === null || (string) ($row->assignee_id ?? 'unassigned') === $employee))->values();
        $teams = $facts->groupBy(fn ($row) => $row->assignee_id ?? 'unassigned')->map(function (Collection $rows) use ($items): array {
            $products = $rows->flatMap(fn ($row) => $items->get($row->key, collect()));

            return ['id' => (string) ($rows->first()->assignee_id ?? 'unassigned'), 'name' => $rows->first()->assignee_name ?? 'غير مسند', ...$this->totals($rows),
                'contents' => $products->groupBy(fn ($item) => $item->item_type.':'.($item->product_id ?? $item->story_id ?? '').':'.$item->title)
                    ->map(fn (Collection $entries): array => ['title' => $entries->first()->title, 'type' => $entries->first()->item_type, 'quantity' => (int) $entries->sum('quantity')])->values()];
        })->sortBy('name')->values();
        $page = (int) ($filters['page'] ?? 1);
        $pageRows = $selected->forPage($page, 25)->values()->map(function ($row) use ($items): array {
            return (array) $row + ['items' => $items->get($row->key, collect())];
        });
        $rows = new LengthAwarePaginator($pageRows, $selected->count(), 25, $page, ['path' => $request->url(), 'query' => $request->except('page')]);

        return compact('from', 'to', 'day', 'employee', 'canViewValues', 'teams', 'rows') + [
            'daily' => $daily->sortKeysDesc()->values(), 'summary' => $this->totals($employeeFacts),
            'selected_summary' => $this->totals($selected),
            'day_teams' => $day === null ? collect() : $this->teamTotals($selected),
            'sku_breakdown' => $this->skuBreakdown($selected, $items, $canViewValues),
            'undated' => DB::query()->fromSub(clone $base, 'shipments')->whereNull('shipped_at')->where('has_shipping_progress', '>', 0)->count(),
        ];
    }

    /** Current assignee attribution from the same deduplicated shipping facts; no extra queries. */
    private function teamTotals(Collection $rows): Collection
    {
        return $rows->groupBy(fn ($row) => $row->assignee_id ?? 'unassigned')
            ->map(fn (Collection $team): array => [
                'id' => (string) ($team->first()->assignee_id ?? 'unassigned'),
                'name' => $team->first()->assignee_name ?? 'غير مسند',
                ...$this->totals($team),
            ])->sortBy('name')->values();
    }

    private function totals(Collection $rows): array
    {
        return ['shipments' => $rows->count(), 'delivered' => (int) $rows->sum('is_delivered'),
            'in_transit' => $rows->count() - (int) $rows->sum('is_delivered'), 'products' => (int) $rows->sum('product_quantity'),
            'stories' => (int) $rows->sum('story_quantity'), 'add_ons' => (int) $rows->sum('add_on_quantity'),
            'items' => (int) $rows->sum('product_quantity') + (int) $rows->sum('story_quantity') + (int) $rows->sum('add_on_quantity'),
            'total_cents' => (int) $rows->sum('total_cents'),
            'items_net_cents' => (int) $rows->sum('items_net_cents'),
            'products_net_cents' => (int) $rows->sum('products_net_cents'),
            'stories_net_cents' => (int) $rows->sum('stories_net_cents'),
            'add_ons_net_cents' => (int) $rows->sum('add_ons_net_cents'),
            'unclassified_net_cents' => (int) $rows->sum('unclassified_net_cents'),
            'paid_cents' => (int) $rows->sum('paid_cents'),
            'remaining_cents' => (int) $rows->sum('remaining_cents')];
    }

    /** Reuse canonical checkout balances; explicit keys avoid nested report filter subqueries. */
    private function addFinancials(Collection $facts): void
    {
        $financials = collect();
        foreach ($facts->pluck('key')->chunk(200) as $keys) {
            $financials = $financials->concat(app(OrderFinancialStatistics::class)->checkouts($keys, false)->get());
        }
        $financials = $financials->keyBy('checkout_group_key');
        // Empty historical checkout keys are separate orders, never one merged balance.
        $legacyIds = $facts->filter(fn ($row) => ! $financials->has($row->key))->pluck('representative_id');
        $legacy = collect();
        foreach ($legacyIds->chunk(200) as $ids) {
            $records = DB::table('orders as o')->leftJoin('stories as s', 's.id', '=', 'o.story_id')
                ->whereIn('o.id', $ids)->whereNull('o.deleted_at')
                ->where(fn ($q) => $q->whereNull('o.checkout_group_key')->orWhere('o.checkout_group_key', ''))
                ->select('o.id', 'o.delivery_details', 'o.discount_cents', 'o.paid_amount_cents', 's.price')->get();
            $itemTotals = DB::table('order_items')->whereIn('order_id', $ids)->groupBy('order_id')
                ->selectRaw('order_id, SUM(total_price_cents) as cents')->get()->keyBy('order_id');
            foreach ($records as $record) {
                $delivery = json_decode($record->delivery_details ?? '{}', true) ?: [];
                $record->effective_item_cents = isset($itemTotals[$record->id]) ? (int) $itemTotals[$record->id]->cents
                    : (int) round((float) ($delivery['item_price'] ?? $record->price ?? 0) * 100);
                $record->delivery_cents = (int) round(max(0, (float) ($delivery['delivery_fee'] ?? 0)) * 100);
                $record->total_cents = max(0, $record->effective_item_cents + $record->delivery_cents - (int) $record->discount_cents);
                $legacy->put($record->id, $record);
            }
        }
        foreach ($facts as $row) {
            $money = $financials->get($row->key) ?? $legacy->get($row->representative_id);
            abort_unless($money, 409, 'تغيّرت بيانات الطلب أثناء إعداد التقرير. أعد تحميل الصفحة.');
            $row->items_cents = (int) $money->effective_item_cents;
            $row->discount_cents = (int) $money->discount_cents;
            $row->items_net_cents = max(0, $row->items_cents - $row->discount_cents);
            $row->delivery_cents = (int) $money->delivery_cents;
            $row->total_cents = (int) $money->total_cents;
            $row->paid_cents = max(0, (int) $money->paid_amount_cents);
            $row->remaining_cents = max(0, $row->total_cents - $row->paid_cents);
        }
    }

    /** Allocate the recorded checkout discount proportionally, reconciling every cent.
     * Item totals already include item pricing; only the stored checkout discount is deducted.
     * Largest remainders break ties in the stable category order below.
     */
    private function allocateCategoryValues(Collection $facts, Collection $items): void
    {
        foreach ($facts as $row) {
            $gross = ['stories' => 0, 'products' => 0, 'add_ons' => 0, 'unclassified' => 0];
            foreach ($items->get($row->key, collect()) as $item) {
                $category = $this->itemCategory($item);
                $gross[$category] += (int) $item->gross_cents;
            }
            // Historical orders without items/story must not silently disappear from values.
            $gross['unclassified'] = $row->items_cents - array_sum($gross);
            abort_if(min($gross) < 0, 409, 'تغيّرت قيم عناصر الطلب أثناء إعداد التقرير. أعد تحميل الصفحة.');
            $net = $remainders = [];
            foreach ($gross as $category => $cents) {
                $numerator = $cents * $row->items_net_cents;
                $net[$category] = $row->items_cents > 0 ? intdiv($numerator, $row->items_cents) : 0;
                $remainders[$category] = $row->items_cents > 0 ? $numerator % $row->items_cents : 0;
            }
            arsort($remainders, SORT_NUMERIC);
            $remaining = $row->items_net_cents - array_sum($net);
            foreach (array_keys($remainders) as $category) {
                if ($remaining-- > 0) {
                    $net[$category]++;
                }
            }
            foreach ($net as $category => $cents) {
                $row->{$category.'_net_cents'} = $cents;
                $lines = $items->get($row->key, collect())->filter(fn ($item) => $this->itemCategory($item) === $category)->values();
                $shares = $lineRemainders = [];
                foreach ($lines as $index => $item) {
                    $numerator = (int) $item->gross_cents * $cents;
                    $shares[$index] = $gross[$category] > 0 ? intdiv($numerator, $gross[$category]) : 0;
                    $lineRemainders[$index] = $gross[$category] > 0 ? $numerator % $gross[$category] : 0;
                }
                arsort($lineRemainders, SORT_NUMERIC);
                $left = $cents - array_sum($shares);
                foreach (array_keys($lineRemainders) as $index) {
                    if ($left-- > 0) {
                        $shares[$index]++;
                    }
                }
                foreach ($lines as $index => $item) {
                    $item->net_cents = $shares[$index];
                }
            }
        }
    }

    private function itemCategory(object $item): string
    {
        return match ($item->item_type) {
            'story' => 'stories', 'product' => 'products', default => 'add_ons',
        };
    }

    /** Aggregate the entire selection, not just the current order page. */
    private function skuBreakdown(Collection $facts, Collection $items, bool $withValues): Collection
    {
        $rows = $facts->flatMap(fn ($row) => $items->get($row->key, collect()))
            ->groupBy(fn ($item) => $item->sku_group_key)
            ->map(function (Collection $lines) use ($withValues): array {
                $first = $lines->first();
                $row = ['title' => $first->display_title, 'sku' => $first->sku, 'quantity' => (int) $lines->sum('quantity')];
                if ($withValues) {
                    $row['net_cents'] = (int) $lines->sum('net_cents');
                }

                return $row;
            })->sortBy('title')->values();
        if ($withValues && $facts->sum('unclassified_net_cents') > 0) {
            $rows->push(['title' => 'قيمة بلا محتويات موثقة', 'sku' => null, 'quantity' => 0, 'net_cents' => (int) $facts->sum('unclassified_net_cents')]);
        }

        return $rows;
    }

    private function groupKey(string $alias): string
    {
        $fallback = DB::connection()->getDriverName() === 'sqlite' ? "('order:' || {$alias}.id)" : "CONCAT('order:', {$alias}.id)";

        return "COALESCE(NULLIF({$alias}.checkout_group_key, ''), {$fallback})";
    }

    /** Normalize before aggregating: MariaDB's strict mode rejects the inline
     * COALESCE/CONCAT expression even when it is repeated verbatim in GROUP BY.
     */
    private function keyedOrders(): Builder
    {
        return DB::table('orders as source_order')
            ->select('source_order.id', 'source_order.story_id', 'source_order.shipping_status', 'source_order.deleted_at')
            ->selectRaw($this->groupKey('source_order').' as checkout_key');
    }

    private function factsQuery(): Builder
    {
        $shipped = array_unique(array_merge(['shipped'], OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'shipped', false)));
        $delivered = array_unique(array_merge(['delivered'], OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'delivered', false)));
        $deliveredPlaceholders = implode(',', array_fill(0, count($delivered), '?'));
        $progress = array_unique(array_merge($shipped, $delivered));
        $logs = DB::table('order_status_logs as l')->joinSub($this->keyedOrders(), 'historical', 'historical.id', '=', 'l.order_id')
            ->where('l.status_type', 'shipping')->whereIn('l.status', $shipped)
            ->select('historical.checkout_key')->selectRaw('MIN(l.created_at) as shipped_at')->groupBy('historical.checkout_key');
        // These are the same dispatched/in-transit states used by BostaWebhookService.
        // Delivered/returned/cancelled events alone do not establish a dispatch date.
        $events = DB::table('bosta_shipment_events as e')->join('bosta_shipments as history', 'history.id', '=', 'e.bosta_shipment_id')->whereNull('history.deleted_at')->whereIn('e.state_code', [21, 22, 23, 24, 25, 30, 40, 41])
            ->whereNotNull('e.occurred_at')->selectRaw('history.checkout_group_key, MIN(e.occurred_at) as shipped_at')->groupBy('history.checkout_group_key');
        $itemCounts = DB::table('order_items')->selectRaw("order_id, COUNT(*) as item_count,
            SUM(CASE WHEN item_type = 'product' THEN quantity ELSE 0 END) as product_quantity,
            SUM(CASE WHEN item_type = 'story' THEN quantity ELSE 0 END) as story_quantity,
            SUM(CASE WHEN item_type NOT IN ('product', 'story') THEN quantity ELSE 0 END) as add_on_quantity")
            ->groupBy('order_id');
        $placeholders = implode(',', array_fill(0, count($progress), '?'));
        $groups = DB::query()->fromSub($this->keyedOrders(), 'o')->whereNull('o.deleted_at')
            ->whereNotIn('o.checkout_key', DB::table('order_group_merge_aliases')->select('source_checkout_group_key'))
            ->leftJoinSub($itemCounts, 'ic', 'ic.order_id', '=', 'o.id')
            ->select('o.checkout_key')->selectRaw('MIN(o.id) as representative_id,
                SUM(COALESCE(ic.product_quantity, 0)) as product_quantity,
                SUM(CASE WHEN COALESCE(ic.item_count, 0) = 0 AND o.story_id IS NOT NULL THEN 1 ELSE COALESCE(ic.story_quantity, 0) END) as story_quantity,
                SUM(COALESCE(ic.add_on_quantity, 0)) as add_on_quantity')
            ->selectRaw("MIN(CASE WHEN o.shipping_status IN ({$placeholders}) THEN 1 ELSE 0 END) as has_shipping_progress", $progress)
            ->selectRaw("MIN(CASE WHEN o.shipping_status IN ({$deliveredPlaceholders}) THEN 1 ELSE 0 END) as is_delivered", $delivered)
            ->selectRaw("CASE WHEN COUNT(DISTINCT o.shipping_status) = 1 THEN MAX(o.shipping_status) ELSE 'mixed' END as current_shipping_status")
            ->groupBy('o.checkout_key');

        return DB::query()->fromSub($groups, 'g')
            ->leftJoinSub($logs, 'logs', 'logs.checkout_key', '=', 'g.checkout_key')
            ->leftJoin('bosta_shipments as s', fn ($join) => $join->on('s.checkout_group_key', '=', 'g.checkout_key')->whereNull('s.deleted_at')->whereNull('s.superseded_at'))
            ->leftJoinSub($events, 'events', 'events.checkout_group_key', '=', 'g.checkout_key')
            ->leftJoin('order_group_assignments as a', 'a.checkout_group_key', '=', 'g.checkout_key')
            ->leftJoin('users as u', 'u.id', '=', 'a.assigned_to_user_id')
            ->leftJoin('order_checkout_references as r', 'r.checkout_group_key', '=', 'g.checkout_key')
            ->selectRaw("g.*, g.checkout_key as `key`, COALESCE(events.shipped_at, logs.shipped_at) as shipped_at,
                CASE WHEN events.shipped_at IS NOT NULL THEN 'bosta' ELSE 'order_log' END as date_source,
                s.tracking_number, s.shipping_status as carrier_status, r.short_reference,
                a.assigned_to_user_id as assignee_id, u.name as assignee_name");
    }

    private function items(Collection $keys, bool $withValues): Collection
    {
        $items = collect();
        foreach ($keys->chunk(200) as $batch) {
            $query = DB::table('orders as o')->whereNull('o.deleted_at')->whereIn(DB::raw($this->groupKey('o')), $batch)
                ->leftJoin('order_items as i', 'i.order_id', '=', 'o.id')->leftJoin('stories as story', 'story.id', '=', 'o.story_id')
                ->selectRaw($this->groupKey('o')." as checkout_key, o.id as order_id,
                    COALESCE(i.item_type, 'story') as item_type, i.product_id, i.product_variant_id, i.sku, i.variant_snapshot, COALESCE(i.story_id, o.story_id) as story_id,
                    COALESCE(i.title, story.title, 'قصة') as title, COALESCE(i.quantity, 1) as quantity")
                ->where(fn ($q) => $q->whereNotNull('i.id')->orWhereNotNull('o.story_id'))->orderBy('o.id')->orderBy('i.id');
            if ($withValues) {
                $query->addSelect('i.id as item_id', 'i.total_price_cents', 'o.delivery_details', 'story.price as story_price');
            }
            $query = $query->get();
            $query->each(function ($item): void {
                $variant = json_decode($item->variant_snapshot ?? '{}', true);
                $variant = is_array($variant) ? $variant : [];
                $variantId = $item->product_variant_id ?? ($variant['id'] ?? null);
                $variantName = trim((string) ($variant['name_ar'] ?? $variant['name_en'] ?? ''));
                $item->sku = trim((string) ($variant['sku'] ?? '')) ?: (trim((string) $item->sku) ?: null);
                $identity = $item->item_type === 'story' ? $item->story_id : $item->product_id;
                $item->sku_group_key = json_encode([$item->item_type, $identity === null ? null : (string) $identity, $variantId === null ? null : (string) $variantId, $item->sku,
                    $variantId === null ? $variantName : null,
                    $identity === null && $item->sku === null ? $item->title : null]);
                $item->display_title = $variantName !== '' && ! str_contains($item->title, $variantName)
                    ? $item->title.' — '.$variantName : $item->title;
                unset($item->variant_snapshot);
            });
            if ($withValues) {
                $query->each(function ($item): void {
                    $delivery = json_decode($item->delivery_details ?? '{}', true) ?: [];
                    $item->gross_cents = $item->item_id !== null ? (int) $item->total_price_cents
                        : (int) round((float) ($delivery['item_price'] ?? $item->story_price ?? 0) * 100);
                    unset($item->delivery_details, $item->story_price, $item->total_price_cents, $item->item_id);
                });
            }
            $items = $items->concat($query);
        }

        return $items->groupBy('checkout_key');
    }
}
