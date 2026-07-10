<?php

namespace App\Services\Analytics;

use App\Models\Order;
use App\Models\VisitorCart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LocalCartAnalyticsRepository
{
    public function todaySummary(): array
    {
        $timezone = (string) config('app.timezone', 'Africa/Cairo');
        $today = now($timezone)->startOfDay();
        $yesterday = now($timezone)->subDay()->startOfDay();

        return [
            'purchases_today' => $this->metricCard(
                'مشتريات مكتملة اليوم',
                $this->completedOrdersBetween($today, $today->copy()->endOfDay()),
                $this->completedOrdersBetween($yesterday, $yesterday->copy()->endOfDay()),
            ),
            'revenue_today' => $this->metricCard(
                'إيراد اليوم',
                $this->revenueBetween($today, $today->copy()->endOfDay()),
                $this->revenueBetween($yesterday, $yesterday->copy()->endOfDay()),
            ),
            'conversion_rate_today' => [
                'label' => 'معدل تحويل السلات اليوم',
                'value' => AnalyticsMetricNormalizer::ratio(
                    $this->convertedCartsBetween($today, $today->copy()->endOfDay()),
                    $this->cartsStartedBetween($today, $today->copy()->endOfDay()),
                ),
                'change' => AnalyticsMetricNormalizer::percentage(
                    AnalyticsMetricNormalizer::ratio(
                        $this->convertedCartsBetween($today, $today->copy()->endOfDay()),
                        $this->cartsStartedBetween($today, $today->copy()->endOfDay()),
                    ),
                    AnalyticsMetricNormalizer::ratio(
                        $this->convertedCartsBetween($yesterday, $yesterday->copy()->endOfDay()),
                        $this->cartsStartedBetween($yesterday, $yesterday->copy()->endOfDay()),
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
            $start = Carbon::parse($range->startDate, config('app.timezone'))->startOfDay();
            $end = Carbon::parse($range->endDate, config('app.timezone'))->endOfDay();
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
            $start = Carbon::parse($range->startDate, config('app.timezone'))->startOfDay();
            $end = Carbon::parse($range->endDate, config('app.timezone'))->endOfDay();

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

    private function completedOrdersBetween(Carbon $start, Carbon $end): int
    {
        return Order::whereBetween('created_at', [$start, $end])->count();
    }

    private function revenueBetween(Carbon $start, Carbon $end): float
    {
        $orders = Order::with('items:id,order_id,total_price_cents')
            ->whereBetween('created_at', [$start, $end])
            ->get(['id', 'delivery_details', 'created_at']);

        $itemsTotal = $orders->sum(fn (Order $order): int => (int) $order->items->sum('total_price_cents')) / 100;
        $deliveryTotal = $orders
            ->groupBy(fn (Order $order): string => (string) data_get($order->delivery_details, 'checkout_group', 'order-'.$order->id))
            ->sum(fn (Collection $group): float => (float) data_get($group->first()->delivery_details, 'delivery_fee', 0));

        return round($itemsTotal + $deliveryTotal, 2);
    }

    private function cartsStartedBetween(Carbon $start, Carbon $end): int
    {
        return VisitorCart::whereBetween('first_added_at', [$start, $end])->count();
    }

    private function convertedCartsBetween(Carbon $start, Carbon $end): int
    {
        return VisitorCart::where('status', 'converted')->whereBetween('converted_at', [$start, $end])->count();
    }

    private function itemsAddedBetween(Carbon $start, Carbon $end): int
    {
        return VisitorCart::query()
            ->whereHas('activities', fn ($query) => $query->where('type', 'item_added')->whereBetween('created_at', [$start, $end]))
            ->withCount(['activities as added_count' => fn ($query) => $query->where('type', 'item_added')->whereBetween('created_at', [$start, $end])])
            ->get()
            ->sum('added_count');
    }

    private function checkoutStartedBetween(Carbon $start, Carbon $end): int
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
