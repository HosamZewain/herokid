<?php

namespace App\Services\Analytics;

use App\Models\Order;
use App\Models\VisitorCart;
use App\Support\AppDateTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LocalCartAnalyticsRepository
{
    public function todaySummary(): array
    {
        $timezone = AppDateTime::timezone();
        $today = now($timezone)->startOfDay();
        $yesterday = now($timezone)->subDay()->startOfDay();
        $todayStart = $today->copy()->utc();
        $todayEnd = $today->copy()->endOfDay()->utc();
        $yesterdayStart = $yesterday->copy()->utc();
        $yesterdayEnd = $yesterday->copy()->endOfDay()->utc();

        return [
            'purchases_today' => $this->metricCard(
                'مشتريات مكتملة اليوم',
                $this->completedOrdersBetween($todayStart, $todayEnd),
                $this->completedOrdersBetween($yesterdayStart, $yesterdayEnd),
            ),
            'revenue_today' => $this->metricCard(
                'إيراد اليوم',
                $this->revenueBetween($todayStart, $todayEnd),
                $this->revenueBetween($yesterdayStart, $yesterdayEnd),
            ),
            'conversion_rate_today' => [
                'label' => 'معدل تحويل السلات اليوم',
                'value' => AnalyticsMetricNormalizer::ratio(
                    $this->convertedCartsBetween($todayStart, $todayEnd),
                    $this->cartsStartedBetween($todayStart, $todayEnd),
                ),
                'change' => AnalyticsMetricNormalizer::percentage(
                    AnalyticsMetricNormalizer::ratio(
                        $this->convertedCartsBetween($todayStart, $todayEnd),
                        $this->cartsStartedBetween($todayStart, $todayEnd),
                    ),
                    AnalyticsMetricNormalizer::ratio(
                        $this->convertedCartsBetween($yesterdayStart, $yesterdayEnd),
                        $this->cartsStartedBetween($yesterdayStart, $yesterdayEnd),
                    ),
                ),
            ],
        ];
    }

    public function cartSummary(): array
    {
        return $this->remember('cart-summary', 300, fn (): array => [
            'active_carts' => VisitorCart::where('status', 'active')->count(),
            'abandoned_carts' => VisitorCart::where('status', 'abandoned')->count(),
            'converted_carts' => VisitorCart::where('status', 'converted')->count(),
            'abandoned_value' => ((int) VisitorCart::where('status', 'abandoned')->sum('cart_total_cents')) / 100,
            'conversion_rate' => AnalyticsMetricNormalizer::ratio(
                VisitorCart::where('status', 'converted')->count(),
                VisitorCart::whereIn('status', ['active', 'abandoned', 'converted'])->count(),
            ),
        ]);
    }

    public function funnel(AnalyticsDateRange $range): array
    {
        return $this->remember('funnel:'.$range->cacheSuffix(), 900, function () use ($range): array {
            $start = AppDateTime::utcStartOfDay($range->startDate);
            $end = AppDateTime::utcEndOfDay($range->endDate);
            $steps = [
                ['key' => 'cart_created', 'label' => 'سلات تم إنشاؤها', 'value' => $this->cartsStartedBetween($start, $end)],
                ['key' => 'local_item_added', 'label' => 'عناصر أضيفت للسلة', 'value' => $this->itemsAddedBetween($start, $end)],
                ['key' => 'checkout_started_local', 'label' => 'بدأ إدخال بيانات التوصيل', 'value' => $this->checkoutStartedBetween($start, $end)],
                ['key' => 'purchase_local', 'label' => 'طلبات مكتملة محلياً', 'value' => $this->completedOrdersBetween($start, $end)],
            ];
            $previous = null;

            return collect($steps)->map(function (array $step) use (&$previous): array {
                $value = $step['value'];
                $dropOff = $previous !== null ? max(0, round((($previous - $value) / max(1, $previous)) * 100, 1)) : null;
                $previous = $value;

                return [
                    'event' => $step['key'],
                    'label' => $step['label'],
                    'value' => $value,
                    'available' => true,
                    'drop_off' => $dropOff,
                ];
            })->all();
        });
    }

    public function sourceConversions(AnalyticsDateRange $range): array
    {
        return $this->remember('source-conversions:'.$range->cacheSuffix(), 900, function () use ($range): array {
            $start = AppDateTime::utcStartOfDay($range->startDate);
            $end = AppDateTime::utcEndOfDay($range->endDate);

            return VisitorCart::query()
                ->where('status', 'converted')
                ->whereBetween('converted_at', [$start, $end])
                ->get(['utm_source', 'utm_medium'])
                ->groupBy(fn (VisitorCart $cart): string => $this->sourceLabel($cart))
                ->map(fn (Collection $carts): int => $carts->count())
                ->all();
        });
    }

    public function flush(): void
    {
        $registryKey = 'analytics:local-cart:cache-keys';
        $keys = Cache::get($registryKey, []);

        foreach (is_array($keys) ? $keys : [] as $key) {
            Cache::forget($key);
        }

        Cache::forget($registryKey);
    }

    private function completedOrdersBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return Order::whereBetween('created_at', [$start, $end])->count();
    }

    private function revenueBetween(CarbonInterface $start, CarbonInterface $end): float
    {
        $orders = Order::with('items:id,order_id,total_price_cents')
            ->whereBetween('created_at', [$start, $end])
            ->get(['id', 'delivery_details', 'created_at']);

        $itemsTotal = $orders->sum(fn (Order $order): int => (int) $order->items->sum('total_price_cents')) / 100;
        $deliveryTotal = $orders
            ->groupBy(fn (Order $order): string => (string) data_get($order->delivery_details, 'checkout_group', 'order-'.$order->id))
            ->sum(fn (Collection $group): float => (float) data_get($group->first()->delivery_details, 'delivery_fee', 0));
        $discountTotal = $orders
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey())
            ->sum(fn (Collection $group): float => ((int) $group->max('discount_cents')) / 100);

        return round(max(0, $itemsTotal + $deliveryTotal - $discountTotal), 2);
    }

    private function cartsStartedBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return VisitorCart::whereBetween('first_added_at', [$start, $end])->count();
    }

    private function convertedCartsBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return VisitorCart::where('status', 'converted')->whereBetween('converted_at', [$start, $end])->count();
    }

    private function itemsAddedBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return VisitorCart::query()
            ->whereHas('activities', fn ($query) => $query->where('type', 'item_added')->whereBetween('created_at', [$start, $end]))
            ->withCount(['activities as added_count' => fn ($query) => $query->where('type', 'item_added')->whereBetween('created_at', [$start, $end])])
            ->get()
            ->sum('added_count');
    }

    private function checkoutStartedBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return VisitorCart::whereBetween('checkout_started_at', [$start, $end])->count();
    }

    private function sourceLabel(VisitorCart $cart): string
    {
        $source = trim((string) $cart->utm_source);
        $medium = trim((string) $cart->utm_medium);

        if ($source === '' && $medium === '') {
            return 'Direct';
        }

        return trim($source.($medium !== '' ? ' / '.$medium : ''));
    }

    private function metricCard(string $label, mixed $current, mixed $previous): array
    {
        $current = AnalyticsMetricNormalizer::number($current);
        $previous = AnalyticsMetricNormalizer::number($previous);

        return [
            'label' => $label,
            'value' => $current,
            'change' => AnalyticsMetricNormalizer::percentage($current, $previous),
            'source' => 'local',
        ];
    }

    private function remember(string $name, int $seconds, \Closure $callback): mixed
    {
        $key = 'analytics:local-cart:'.$name;
        $registryKey = 'analytics:local-cart:cache-keys';
        $keys = Cache::get($registryKey, []);
        $keys = is_array($keys) ? $keys : [];

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put($registryKey, $keys, now()->addDay());
        }

        return Cache::remember($key, max(1, $seconds), $callback);
    }
}
