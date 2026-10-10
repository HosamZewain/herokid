<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Services\Sales\SalesReportFilters;
use App\Support\AppDateTime;
use App\Support\OrderSource;
use App\Support\OrderStatusRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/** Read-only cash movement facts. Never substitute order balances for ledger deltas. */
class PaymentCollectionReportService
{
    public const CATEGORIES = ['story' => 'قصص فقط', 'product' => 'منتجات فقط', 'group' => 'باقات / طلبات مختلطة', 'unknown' => 'غير مصنف'];

    public function query(mixed $start, mixed $end): Builder
    {
        return OrderPaymentEvent::query()->where('affects_collection_stats', true)
            ->whereBetween('occurred_at', [$start, $end]);
    }

    /** Dated legacy receipts and ledger receipts share one source across reports. */
    public function movementsBetween(mixed $start, mixed $end, bool $detailed = false): EloquentCollection
    {
        $events = $this->query($start, $end)->orderBy('occurred_at')->orderBy('id')->get([
            'id', 'checkout_group_key', 'order_id', 'actor_user_id', 'event_type', 'source',
            'new_status', 'previous_paid_amount_cents', 'new_paid_amount_cents',
            'amount_delta_cents', 'payment_method', 'occurred_at',
        ]);
        $history = app(HistoricalPaymentSource::class)->history()['events']
            ->filter(fn (OrderPaymentEvent $event): bool => $event->occurred_at->gte($start) && $event->occurred_at->lte($end));
        $events = new EloquentCollection([...$events->all(), ...$history->all()]);
        if ($detailed) {
            $events->loadMissing(['actor:id,name', 'order.checkoutReference']);
            $merged = $events->mapWithKeys(function (OrderPaymentEvent $event): array {
                $key = $event->checkout_group_key;
                $target = app(PaymentReconciliationService::class)->canonicalKey($key);

                return $key === $target ? [] : [$key => $target];
            });
            if ($merged->isNotEmpty()) {
                // Display/link the current target without rewriting the source event.
                $targets = Order::withTrashed()->whereIn('checkout_group_key', $merged->values()->unique())
                    ->orderBy('id')->get(['id', 'checkout_group_key', 'deleted_at'])->load('checkoutReference')
                    ->groupBy('checkout_group_key')->map(fn (Collection $orders): Order => $orders->firstWhere('deleted_at', null) ?? $orders->first());
                foreach ($events as $event) {
                    $target = $targets->get($merged->get($event->checkout_group_key));
                    if ($target) {
                        $event->setRelation('order', $target);
                    }
                }
            }
        }

        return $events;
    }

    /** Compact context is loaded once per bounded batch, including deleted history. */
    public function events(SalesReportFilters $filters, bool $detailed = false): Collection
    {
        $rows = collect();
        foreach ($this->movementsBetween($filters->start(), $filters->end(), $detailed)->chunk(200) as $events) {
            $events = collect($events->all());
            $contexts = $this->contexts($events->pluck('checkout_group_key')->unique(), $detailed);
            foreach ($events as $event) {
                $context = $contexts->get($event->checkout_group_key, $this->missingContext($event->checkout_group_key));
                if (! $this->matches($context, $event, $filters)) {
                    continue;
                }
                // Item filters select their proportional share, never the whole payment again.
                $items = $this->allocate($context['items'], (int) $event->amount_delta_cents);
                $selected = $items->filter(fn (array $item): bool => ($filters->type === 'all' || $item['type'] === $filters->type)
                    && (! $filters->item || $item['catalog_key'] === $filters->item));
                $delta = $filters->type === 'all' && ! $filters->item
                    ? (int) $event->amount_delta_cents : (int) $selected->sum('collected_cents');
                if (($filters->type !== 'all' || $filters->item) && $selected->isEmpty()) {
                    continue;
                }
                $rows->push(array_replace($context, [
                    'id' => $event->id, 'occurred_at' => $event->occurred_at,
                    'created_at' => $event->occurred_at, 'original_created_at' => $context['created_at'],
                    'paid_amount_cents' => $delta, 'amount_delta_cents' => $delta,
                    'items' => $selected->values()->all(),
                    'payment_method' => $event->payment_method, 'event_type' => $event->event_type,
                    'event_source' => $event->source, 'new_paid_amount_cents' => (int) $event->new_paid_amount_cents,
                    'historical' => $event->source === 'historical_admin_activity',
                    'original_checkout_key' => $event->checkout_group_key,
                    'actor_name' => $detailed ? ($event->actor?->name ?: 'غير مسجل') : null,
                ]));
            }
        }

        return $rows->sortBy([['occurred_at', 'desc'], [fn (array $a, array $b): int => abs($b['id']) <=> abs($a['id'])]])->values();
    }

    public function summary(Collection $events): array
    {
        return ['net_cents' => (int) $events->sum('amount_delta_cents'),
            'received_cents' => (int) $events->where('amount_delta_cents', '>', 0)->sum('amount_delta_cents'),
            'reversed_cents' => -(int) $events->where('amount_delta_cents', '<', 0)->sum('amount_delta_cents'),
            'received_count' => $events->where('amount_delta_cents', '>', 0)->count(),
            'reversed_count' => $events->where('amount_delta_cents', '<', 0)->count(),
            'checkouts' => $events->pluck('key')->unique()->count()];
    }

    /** Breakdown rows count each checkout/item once, regardless of its number of payments. */
    public function checkouts(Collection $events): Collection
    {
        return $events->groupBy('key')->map(function (Collection $same): array {
            $row = $same->first();
            $row['paid_amount_cents'] = (int) $same->sum('amount_delta_cents');
            $row['items'] = $same->flatMap(fn (array $event): array => $event['items'])->groupBy('identity')
                ->map(fn (Collection $items): array => array_replace($items->first(), ['collected_cents' => (int) $items->sum('collected_cents')]))->values()->all();

            return $row;
        })->values();
    }

    public function daily(Collection $events, SalesReportFilters $filters): Collection
    {
        $byDate = $events->groupBy(fn (array $row): string => AppDateTime::format($row['occurred_at'], 'Y-m-d'));
        $days = collect();
        for ($date = $filters->localStart(); $date->lte($filters->localEnd()); $date = $date->addDay()) {
            $same = $byDate->get($date->toDateString(), collect());
            $day = ['date' => $date->toDateString(), ...$this->summary($same)];
            foreach (self::CATEGORIES as $category => $label) {
                $day[$category.'_cents'] = (int) $same->where('category', $category)->sum('amount_delta_cents');
            }
            $days->push($day);
        }

        return $days->reverse()->values();
    }

    public function trend(Collection $events, SalesReportFilters $filters): array
    {
        $groupBy = $filters->resolvedGroupBy();

        return $this->daily($events, $filters)->reverse()->groupBy(function (array $day) use ($groupBy): string {
            $date = CarbonImmutable::parse($day['date'], AppDateTime::timezone());

            return match ($groupBy) {
                'month' => $date->format('Y-m'), 'week' => $date->startOfWeek()->toDateString(), default => $day['date']
            };
        })->map(fn (Collection $days, string $key): array => ['key' => $key, 'label' => $key, 'total' => round((int) $days->sum('net_cents') / 100, 2)])->values()->all();
    }

    private function contexts(Collection $keys, bool $detailed): Collection
    {
        $canonical = $keys->mapWithKeys(fn (string $key): array => [$key => app(PaymentReconciliationService::class)->canonicalKey($key)]);
        $orders = Order::withTrashed()->whereIn('checkout_group_key', $canonical->values()->unique())->orderBy('id')
            ->select(['id', 'checkout_group_key', 'order_number', 'user_id', 'parent_name', 'story_id', 'status',
                'child_name', 'delivery_details', 'order_source', 'discount_cents', 'created_at', 'deleted_at'])
            ->with(['items:id,order_id,item_type,story_id,product_id,title,sku,quantity,total_price_cents,item_snapshot', 'story:id,title,price', 'marketingCart', 'checkoutReference']);
        if ($detailed) {
            $orders->with('user:id,name');
        }

        $contexts = $orders->get()->groupBy('checkout_group_key')->map(function (Collection $history, string $key) use ($detailed): array {
            $live = $history->whereNull('deleted_at');
            // Superseded rows must not inflate the present composition of a live checkout.
            $group = $live->isNotEmpty() ? $live : $history;
            $first = $group->first();
            $delivery = $first->delivery_details ?? [];
            $items = $group->flatMap(function (Order $order): array {
                $items = $order->items->map(fn ($item): array => [
                    'identity' => 'item:'.$item->id, 'type' => $item->item_type,
                    'catalog_key' => $item->story_id ? 'story:'.$item->story_id : 'product:'.$item->product_id,
                    'title' => $item->title, 'sku' => $item->sku, 'quantity' => max(1, (int) $item->quantity),
                    'total_cents' => max(0, (int) $item->total_price_cents),
                    'package' => filled(data_get($item->item_snapshot, 'package.id')),
                ])->all();
                if ($items === [] && $order->story_id) {
                    $items[] = ['identity' => 'legacy:'.$order->id, 'type' => 'story', 'catalog_key' => 'story:'.$order->story_id,
                        'title' => $order->story?->title ?: 'قصة مخصصة', 'sku' => null, 'quantity' => 1,
                        'total_cents' => (int) round((float) (data_get($order->delivery_details, 'item_price') ?? $order->story?->price ?? 0) * 100), 'package' => false];
                }

                return $items;
            })->values();
            $story = $items->contains('type', 'story');
            $product = $items->contains(fn (array $item): bool => $item['type'] !== 'story');
            $category = $items->contains('package', true) || ($story && $product) ? 'group' : ($story ? 'story' : ($product ? 'product' : 'unknown'));
            $cart = $group->first(fn (Order $order): bool => filled($order->marketingCart?->utm_source))?->marketingCart;
            $source = filled($cart?->utm_source) ? (string) $cart->utm_source : (($first->order_source ?: 'website') === 'website' ? 'direct' : $first->order_source);
            $total = max(0, (int) $items->sum('total_cents') + (int) round(max(0, (float) ($delivery['delivery_fee'] ?? 0)) * 100) - (int) $group->max('discount_cents'));

            return ['key' => $key, 'first_order_id' => $first->id, 'reference' => $first->checkoutReference?->short_reference ?: $key,
                'created_at' => $history->min('created_at'), 'category' => $category, 'category_label' => self::CATEGORIES[$category],
                'customer_name' => $detailed ? ($first->parent_name ?: $first->user?->name ?: 'زائر') : null,
                'customer_key' => $first->user_id ? 'user:'.$first->user_id : 'guest:'.sha1((string) (filled($delivery['phone'] ?? null) ? $delivery['phone'] : $key)),
                'customer_type' => $first->user_id ? 'registered' : 'guest',
                'country_id' => $delivery['delivery_country_id'] ?? null, 'governorate_id' => $delivery['delivery_governorate_id'] ?? null,
                'country' => (string) ($delivery['country'] ?? 'غير محدد'), 'governorate' => (string) ($delivery['governorate'] ?? 'غير محدد'),
                'source_key' => $source, 'source' => $source === 'direct' ? OrderSource::label('website') : ($cart ? trim($source.' '.($cart->utm_medium ?? '')) : OrderSource::label($source)),
                'statuses' => $group->pluck('status')->all(), 'total_cents' => $total, 'items' => $items->all(),
                'search_text' => implode(' ', [$key, $first->checkoutReference?->short_reference, $first->parent_name, $delivery['phone'] ?? '', ...$group->pluck('child_name')->all(), ...$group->pluck('order_number')->all(), ...$items->pluck('title')->all(), ...$items->pluck('sku')->all()])];
        });

        return $canonical->map(fn (string $key): array => $contexts->get($key, $this->missingContext($key)));
    }

    private function missingContext(string $key): array
    {
        return ['key' => $key, 'first_order_id' => null, 'reference' => $key, 'created_at' => null,
            'category' => 'unknown', 'category_label' => self::CATEGORIES['unknown'], 'customer_name' => null,
            'customer_key' => $key, 'customer_type' => 'guest', 'country_id' => null, 'governorate_id' => null,
            'country' => 'غير محدد', 'governorate' => 'غير محدد', 'source_key' => 'unknown', 'source' => 'غير محدد',
            'statuses' => [], 'total_cents' => 0, 'items' => [], 'search_text' => $key];
    }

    private function matches(array $row, OrderPaymentEvent $event, SalesReportFilters $filters): bool
    {
        $cancelled = collect($row['statuses'])->contains(fn (string $status): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $status) === 'cancelled');

        return ($filters->status === 'all' || ($filters->status === 'active' ? ! $cancelled : in_array($filters->status, $row['statuses'], true)))
            && ($filters->paymentStatus === 'all' || $event->new_status === $filters->paymentStatus)
            && ($filters->customerType === 'all' || $row['customer_type'] === $filters->customerType)
            && (! $filters->countryId || (int) $row['country_id'] === $filters->countryId)
            && (! $filters->governorateId || (int) $row['governorate_id'] === $filters->governorateId)
            && (! $filters->source || $row['source_key'] === $filters->source)
            && (! $filters->search || mb_stripos($row['search_text'], $filters->search) !== false)
            && ($filters->minimumTotal === null || $row['total_cents'] >= (int) round($filters->minimumTotal * 100))
            && ($filters->maximumTotal === null || $row['total_cents'] <= (int) round($filters->maximumTotal * 100));
    }

    private function allocate(array $items, int $delta): Collection
    {
        $gross = (int) collect($items)->sum('total_cents');
        $allocated = 0;
        $cumulative = 0;

        return collect($items)->map(function (array $item, int $index) use ($items, $gross, $delta, &$allocated, &$cumulative): array {
            $cumulative += $item['total_cents'];
            // Integer cumulative rounding preserves every cent, including negative movements.
            $next = $index === count($items) - 1 ? $delta : ($gross > 0 ? intdiv($delta * $cumulative, $gross) : 0);
            $item['collected_cents'] = $next - $allocated;
            $allocated = $next;

            return $item;
        });
    }
}
