<?php

namespace App\Services\Sales;

use App\Models\DeliveryCountry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Story;
use App\Models\VisitorCart;
use App\Services\Analytics\AnalyticsMetricNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SalesReportService
{
    public function report(SalesReportFilters $filters): array
    {
        $rows = $this->rows($filters);
        $previousRows = $this->rows($filters->previousPeriod());
        $summary = $this->summary($rows);
        $previousSummary = $this->summary($previousRows);

        return [
            'summary' => $summary,
            'comparison' => [
                'total' => AnalyticsMetricNormalizer::percentage($summary['total'], $previousSummary['total']),
                'checkouts' => AnalyticsMetricNormalizer::percentage($summary['checkouts'], $previousSummary['checkouts']),
                'average_checkout' => AnalyticsMetricNormalizer::percentage($summary['average_checkout'], $previousSummary['average_checkout']),
                'items_quantity' => AnalyticsMetricNormalizer::percentage($summary['items_quantity'], $previousSummary['items_quantity']),
                'previous_total' => $previousSummary['total'],
                'previous_checkouts' => $previousSummary['checkouts'],
            ],
            'trend' => $this->trend($rows, $filters),
            'top_items' => $this->topItems($rows),
            'type_breakdown' => $this->typeBreakdown($rows),
            'status_breakdown' => $this->statusBreakdown($rows),
            'source_breakdown' => $this->sourceBreakdown($rows),
            'geography_breakdown' => $this->geographyBreakdown($rows),
            'customer_breakdown' => $this->customerBreakdown($rows),
            'rows' => $rows,
            'options' => $this->options(),
        ];
    }

    public function rows(SalesReportFilters $filters): Collection
    {
        $orders = $this->orderQuery($filters)->get();
        $carts = VisitorCart::query()
            ->whereIn('related_order_id', $orders->pluck('id'))
            ->get(['related_order_id', 'utm_source', 'utm_medium', 'utm_campaign'])
            ->keyBy('related_order_id');

        $rows = $orders
            ->groupBy(fn (Order $order): string => $this->checkoutKey($order))
            ->map(function (Collection $group) use ($filters, $carts): ?array {
                $orders = $group->sortBy('id')->values();
                $items = $orders->flatMap(fn (Order $order): array => $this->orderItems($order, $filters))->values();

                if ($items->isEmpty()) {
                    return null;
                }

                $first = $orders->first();
                $cart = $carts->first(fn (VisitorCart $cart): bool => $orders->contains('id', $cart->related_order_id));
                $itemsTotalCents = (int) $items->sum('total_cents');
                $deliveryCents = (int) round(max(0, (float) data_get($first->delivery_details, 'delivery_fee', 0)) * 100);
                $totalCents = $itemsTotalCents + $deliveryCents;
                $statuses = $orders->pluck('status')->filter()->unique()->values();
                $phone = trim((string) data_get($first->delivery_details, 'phone', ''));
                $customerKey = $first->user_id ? 'user-'.$first->user_id : 'guest-'.sha1($phone ?: $this->checkoutKey($first));

                return [
                    'key' => $this->checkoutKey($first),
                    'created_at' => $first->created_at,
                    'date' => $first->created_at?->timezone((string) config('app.timezone', 'Africa/Cairo'))->format('Y-m-d H:i'),
                    'order_ids' => $orders->pluck('id')->values()->all(),
                    'order_numbers' => $orders->pluck('order_number')->values()->all(),
                    'first_order_id' => $first->id,
                    'order_records' => $orders->count(),
                    'statuses' => $statuses->all(),
                    'status_label' => $statuses->map(fn (string $status): string => (string) __('order_status.'.$status))->implode('، '),
                    'customer_name' => $first->parent_name ?: $first->user?->name ?: 'زائر',
                    'customer_key' => $customerKey,
                    'customer_type' => $first->user_id ? 'registered' : 'guest',
                    'phone' => $phone,
                    'country' => (string) data_get($first->delivery_details, 'country', 'غير محدد'),
                    'governorate' => (string) data_get($first->delivery_details, 'governorate', 'غير محدد'),
                    'city' => (string) data_get($first->delivery_details, 'city', ''),
                    'source' => $this->sourceLabel($cart),
                    'source_key' => $cart && filled($cart->utm_source) ? (string) $cart->utm_source : 'direct',
                    'campaign' => $cart?->utm_campaign,
                    'items' => $items->all(),
                    'items_summary' => $items->groupBy(fn (array $item): string => $item['type'].'|'.$item['title'])
                        ->map(fn (Collection $same): string => $same->first()['title'].' × '.$same->sum('quantity'))
                        ->values()->implode('، '),
                    'items_quantity' => (int) $items->sum('quantity'),
                    'items_total_cents' => $itemsTotalCents,
                    'delivery_cents' => $deliveryCents,
                    'total_cents' => $totalCents,
                    'orders' => $orders->map(function (Order $order) use ($items): array {
                        return [
                            'id' => $order->id,
                            'status' => $order->status,
                            'items_total_cents' => (int) $items->where('order_id', $order->id)->sum('total_cents'),
                        ];
                    })->all(),
                ];
            })
            ->filter()
            ->when($filters->source, function (Collection $rows, string $source): Collection {
                return $rows->filter(fn (array $row): bool => $row['source_key'] === $source);
            })
            ->when($filters->minimumTotal !== null, function (Collection $rows) use ($filters): Collection {
                return $rows->filter(fn (array $row): bool => $row['total_cents'] >= (int) round($filters->minimumTotal * 100));
            })
            ->when($filters->maximumTotal !== null, function (Collection $rows) use ($filters): Collection {
                return $rows->filter(fn (array $row): bool => $row['total_cents'] <= (int) round($filters->maximumTotal * 100));
            });

        return match ($filters->sort) {
            'oldest' => $rows->sortBy([['created_at', 'asc'], ['key', 'asc']])->values(),
            'highest' => $rows->sortByDesc('total_cents')->values(),
            'lowest' => $rows->sortBy('total_cents')->values(),
            default => $rows->sortByDesc('created_at')->values(),
        };
    }

    private function orderQuery(SalesReportFilters $filters): Builder
    {
        $query = Order::query()
            ->with([
                'items:id,order_id,item_type,story_id,product_id,title,sku,unit_price_cents,quantity,total_price_cents',
                'story:id,title,price',
                'user:id,name',
            ])
            ->whereBetween('created_at', [$filters->start(), $filters->end()]);

        if ($filters->status === 'active') {
            $query->where('status', '!=', 'cancelled');
        } elseif ($filters->status !== 'all') {
            $query->where('status', $filters->status);
        }

        if ($filters->customerType === 'registered') {
            $query->whereNotNull('user_id');
        } elseif ($filters->customerType === 'guest') {
            $query->whereNull('user_id');
        }

        $query
            ->when($filters->countryId, fn (Builder $builder, int $id): Builder => $builder->where('delivery_details->delivery_country_id', $id))
            ->when($filters->governorateId, fn (Builder $builder, int $id): Builder => $builder->where('delivery_details->delivery_governorate_id', $id));

        if ($filters->type !== 'all') {
            $this->whereItemType($query, $filters->type);
        }

        if ($filters->item) {
            [$kind, $id] = explode(':', $filters->item, 2);
            $query->where(function (Builder $builder) use ($kind, $id): void {
                $builder->whereHas('items', fn (Builder $items): Builder => $items->where($kind.'_id', (int) $id));

                if ($kind === 'story') {
                    $builder->orWhere(function (Builder $legacy) use ($id): void {
                        $legacy->where('story_id', (int) $id)->whereDoesntHave('items');
                    });
                }
            });
        }

        if ($filters->search) {
            $term = $filters->search;
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('parent_name', 'like', '%'.$term.'%')
                    ->orWhere('child_name', 'like', '%'.$term.'%')
                    ->orWhere('delivery_details->phone', 'like', '%'.$term.'%')
                    ->orWhereHas('items', fn (Builder $items): Builder => $items
                        ->where('title', 'like', '%'.$term.'%')
                        ->orWhere('sku', 'like', '%'.$term.'%'))
                    ->orWhereHas('story', fn (Builder $story): Builder => $story->where('title', 'like', '%'.$term.'%'));
            });
        }

        return $query;
    }

    private function whereItemType(Builder $query, string $type): void
    {
        $query->where(function (Builder $builder) use ($type): void {
            $builder->whereHas('items', fn (Builder $items): Builder => $items->where('item_type', $type));

            if ($type === 'story') {
                $builder->orWhere(function (Builder $legacy): void {
                    $legacy->whereNotNull('story_id')->whereDoesntHave('items');
                });
            }
        });
    }

    private function orderItems(Order $order, SalesReportFilters $filters): array
    {
        $items = $order->items->map(fn ($item): array => [
            'order_id' => $order->id,
            'type' => (string) $item->item_type,
            'story_id' => $item->story_id ? (int) $item->story_id : null,
            'product_id' => $item->product_id ? (int) $item->product_id : null,
            'title' => (string) $item->title,
            'sku' => $item->sku,
            'quantity' => max(1, (int) $item->quantity),
            'unit_cents' => (int) $item->unit_price_cents,
            'total_cents' => (int) $item->total_price_cents,
            'status' => $order->status,
        ])->all();

        if ($items === [] && $order->story_id) {
            $priceCents = (int) round((float) (data_get($order->delivery_details, 'item_price') ?? $order->story?->price ?? 0) * 100);
            $items[] = [
                'order_id' => $order->id,
                'type' => 'story',
                'story_id' => (int) $order->story_id,
                'product_id' => null,
                'title' => (string) ($order->story?->title ?? 'قصة مخصصة'),
                'sku' => null,
                'quantity' => 1,
                'unit_cents' => $priceCents,
                'total_cents' => $priceCents,
                'status' => $order->status,
            ];
        }

        return collect($items)
            ->filter(fn (array $item): bool => $this->itemMatches($item, $filters))
            ->values()
            ->all();
    }

    private function itemMatches(array $item, SalesReportFilters $filters): bool
    {
        if ($filters->type !== 'all' && $item['type'] !== $filters->type) {
            return false;
        }

        if (! $filters->item) {
            return true;
        }

        [$kind, $id] = explode(':', $filters->item, 2);

        return (int) ($item[$kind.'_id'] ?? 0) === (int) $id;
    }

    private function summary(Collection $rows): array
    {
        $itemsCents = (int) $rows->sum('items_total_cents');
        $deliveryCents = (int) $rows->sum('delivery_cents');
        $totalCents = $itemsCents + $deliveryCents;
        $checkouts = $rows->count();

        return [
            'total' => round($totalCents / 100, 2),
            'items_sales' => round($itemsCents / 100, 2),
            'delivery' => round($deliveryCents / 100, 2),
            'checkouts' => $checkouts,
            'order_records' => (int) $rows->sum('order_records'),
            'items_quantity' => (int) $rows->sum('items_quantity'),
            'average_checkout' => $checkouts > 0 ? round(($totalCents / 100) / $checkouts, 2) : 0,
            'unique_customers' => $rows->pluck('customer_key')->unique()->count(),
        ];
    }

    private function trend(Collection $rows, SalesReportFilters $filters): array
    {
        $groupBy = $filters->resolvedGroupBy();
        $periods = [];
        $cursor = $filters->start()->startOfDay();
        $end = $filters->end()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $this->periodKey($cursor, $groupBy);
            $periods[$key] ??= [
                'key' => $key,
                'label' => $this->periodLabel($cursor, $groupBy),
                'total' => 0.0,
                'items_sales' => 0.0,
                'delivery' => 0.0,
                'checkouts' => 0,
                'items_quantity' => 0,
            ];
            $cursor = match ($groupBy) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow()->startOfMonth(),
                default => $cursor->addDay(),
            };
        }

        foreach ($rows as $row) {
            $date = CarbonImmutable::instance($row['created_at']);
            $key = $this->periodKey($date, $groupBy);
            $periods[$key] ??= [
                'key' => $key,
                'label' => $this->periodLabel($date, $groupBy),
                'total' => 0.0,
                'items_sales' => 0.0,
                'delivery' => 0.0,
                'checkouts' => 0,
                'items_quantity' => 0,
            ];
            $periods[$key]['total'] += $row['total_cents'] / 100;
            $periods[$key]['items_sales'] += $row['items_total_cents'] / 100;
            $periods[$key]['delivery'] += $row['delivery_cents'] / 100;
            $periods[$key]['checkouts']++;
            $periods[$key]['items_quantity'] += $row['items_quantity'];
        }

        return array_values($periods);
    }

    private function topItems(Collection $rows): array
    {
        return $rows->flatMap(fn (array $row): array => collect($row['items'])->map(fn (array $item): array => $item + ['checkout_key' => $row['key']])->all())
            ->groupBy(fn (array $item): string => $item['type'].'|'.$item['title'])
            ->map(fn (Collection $items): array => [
                'title' => $items->first()['title'],
                'type' => $items->first()['type'],
                'quantity' => (int) $items->sum('quantity'),
                'sales' => round(((int) $items->sum('total_cents')) / 100, 2),
                'checkouts' => $items->pluck('checkout_key')->unique()->count(),
            ])
            ->sortByDesc('sales')
            ->take(10)
            ->values()
            ->all();
    }

    private function typeBreakdown(Collection $rows): array
    {
        $labels = ['story' => 'قصص مخصصة', 'product' => 'منتجات مباشرة', 'product_add_on' => 'إضافات مرتبطة بقصة'];

        return $rows->flatMap(fn (array $row): array => $row['items'])
            ->groupBy('type')
            ->map(fn (Collection $items, string $type): array => [
                'key' => $type,
                'label' => $labels[$type] ?? $type,
                'quantity' => (int) $items->sum('quantity'),
                'sales' => round(((int) $items->sum('total_cents')) / 100, 2),
            ])
            ->sortByDesc('sales')
            ->values()
            ->all();
    }

    private function statusBreakdown(Collection $rows): array
    {
        return $rows->flatMap(fn (array $row): array => $row['orders'])
            ->groupBy('status')
            ->map(fn (Collection $orders, string $status): array => [
                'status' => $status,
                'label' => (string) __('order_status.'.$status),
                'orders' => $orders->count(),
                'items_sales' => round(((int) $orders->sum('items_total_cents')) / 100, 2),
            ])
            ->sortByDesc('orders')
            ->values()
            ->all();
    }

    private function sourceBreakdown(Collection $rows): array
    {
        return $rows->groupBy('source')
            ->map(fn (Collection $same, string $source): array => [
                'label' => $source,
                'checkouts' => $same->count(),
                'sales' => round(((int) $same->sum('total_cents')) / 100, 2),
            ])
            ->sortByDesc('sales')
            ->values()
            ->all();
    }

    private function geographyBreakdown(Collection $rows): array
    {
        return $rows->groupBy(fn (array $row): string => trim($row['country'].' / '.$row['governorate'], ' /'))
            ->map(fn (Collection $same, string $label): array => [
                'label' => $label ?: 'غير محدد',
                'checkouts' => $same->count(),
                'sales' => round(((int) $same->sum('total_cents')) / 100, 2),
                'customers' => $same->pluck('customer_key')->unique()->count(),
            ])
            ->sortByDesc('sales')
            ->take(10)
            ->values()
            ->all();
    }

    private function customerBreakdown(Collection $rows): array
    {
        $labels = ['registered' => 'عملاء مسجلون', 'guest' => 'زوار بدون حساب'];

        return $rows->groupBy('customer_type')
            ->map(fn (Collection $same, string $type): array => [
                'label' => $labels[$type] ?? $type,
                'checkouts' => $same->count(),
                'sales' => round(((int) $same->sum('total_cents')) / 100, 2),
                'customers' => $same->pluck('customer_key')->unique()->count(),
            ])
            ->sortByDesc('sales')
            ->values()
            ->all();
    }

    private function options(): array
    {
        return [
            'stories' => Story::orderBy('title')->get(['id', 'title']),
            'products' => Product::orderBy('name_ar')->get(['id', 'name_ar']),
            'countries' => DeliveryCountry::with(['governorates' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get(),
            'sources' => VisitorCart::query()->whereNotNull('utm_source')->where('utm_source', '!=', '')->distinct()->orderBy('utm_source')->pluck('utm_source')->values(),
        ];
    }

    private function checkoutKey(Order $order): string
    {
        $group = trim((string) data_get($order->delivery_details, 'checkout_group', ''));

        return $group !== '' ? $group : 'ORDER-'.$order->id;
    }

    private function sourceLabel(?VisitorCart $cart): string
    {
        if (! $cart || blank($cart->utm_source)) {
            return 'مباشر / غير معروف';
        }

        return trim((string) $cart->utm_source.(filled($cart->utm_medium) ? ' / '.$cart->utm_medium : ''));
    }

    private function periodKey(CarbonImmutable $date, string $groupBy): string
    {
        return match ($groupBy) {
            'week' => $date->startOfWeek()->toDateString(),
            'month' => $date->format('Y-m'),
            default => $date->toDateString(),
        };
    }

    private function periodLabel(CarbonImmutable $date, string $groupBy): string
    {
        return match ($groupBy) {
            'week' => 'أسبوع '.$date->startOfWeek()->format('d/m'),
            'month' => $date->format('m/Y'),
            default => $date->format('d/m'),
        };
    }
}
