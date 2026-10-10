<?php

namespace App\Services\Sales;

use App\Models\Order;
use App\Services\Payments\PaymentCollectionReportService;
use App\Support\MarketingAttribution;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Aggregate purchase evidence, not ad-platform spend or inferred customer locations. */
class AdvertisingTargetingReportService
{
    public function __construct(private SalesReportService $sales) {}

    public function report(Request $request, bool $export = false): array
    {
        // Never apply item-level sales filters before allocating a checkout's discount.
        $dates = SalesReportFilters::fromRequest(Request::create('/', 'GET', array_filter($request->only('range', 'start_date', 'end_date'), 'is_string')));
        $level = in_array($request->query('level'), ['governorate', 'city', 'district'], true) ? $request->query('level') : 'district';
        $basis = $request->query('basis') === 'collected' ? 'collected' : 'ordered';
        $cash = app(PaymentCollectionReportService::class)->checkouts(app(PaymentCollectionReportService::class)->events($dates))->keyBy('key');
        if ($basis === 'collected') {
            // Marketing cash mode selects payment-date keys, including older purchases.
            $allDates = SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => 'custom', 'start_date' => '1900-01-01', 'end_date' => '2200-01-01']));
            $facts = $cash->isEmpty() ? collect() : $this->sales->rows($allDates, detailed: false, onlyKeys: $cash->keys());
        } else {
            $facts = $this->sales->rows($dates, detailed: false);
        }
        $facts = $this->enrich($facts)->map(fn (array $row): array => $row + ['period_collection_cents' => (int) ($cash->get($row['key'])['paid_amount_cents'] ?? 0)]);
        $allCount = $facts->count();
        $cancelled = $facts->where('cancelled', true)->count();
        $facts = $facts->where('cancelled', false)->values();
        if ($basis === 'collected') {
            $facts = $facts->filter(fn (array $row): bool => $cash->has($row['key']))->values();
        }
        $location = is_string($request->query('location')) ? mb_substr(trim($request->query('location')), 0, 100) : '';
        if ($location !== '') {
            $facts = $facts->filter(fn (array $row): bool => mb_stripos($row['geography']['district']['label'], $location) !== false)->values();
        }
        $options = $facts->flatMap(fn (array $row): array => $row['items'])->unique('catalog_key')
            ->map(fn (array $item): array => ['key' => $item['catalog_key'], 'label' => $item['title']])->sortBy('label')->values();
        $itemKey = is_string($request->query('item')) ? $request->query('item') : '';
        if ($itemKey !== '') {
            $facts = $facts->filter(fn (array $row): bool => collect($row['items'])->contains('catalog_key', $itemKey))->values();
        }
        $areas = $facts->groupBy(fn (array $row): string => $row['geography'][$level]['key'])
            ->map(function (Collection $rows) use ($level): array {
                $place = $rows->first()['geography'][$level];

                return $place + $this->summary($rows) + ['top_items' => $this->items($rows)->take(3)->all()];
            })->sortBy([['net_cents', 'desc'], ['checkouts', 'desc'], ['key', 'asc']])->values();
        $areaKey = is_string($request->query('area')) ? $request->query('area') : '';
        $selected = $areaKey === '' ? null : $areas->firstWhere('key', $areaKey);
        // An invalid selection must not silently broaden the report to every area.
        $selectedFacts = $areaKey === '' ? $facts : $facts->filter(fn (array $row): bool => $row['geography'][$level]['key'] === $areaKey)->values();
        $items = $this->items($selectedFacts);
        $campaigns = $selectedFacts->groupBy(fn (array $row): string => hash('sha256', json_encode($row['attribution'], JSON_UNESCAPED_UNICODE)))
            ->map(fn (Collection $rows): array => $rows->first()['attribution'] + $this->summary($rows))
            ->sortBy([['checkouts', 'desc'], ['net_cents', 'desc']])->values();

        return [
            'dates' => $dates, 'level' => $level, 'basis' => $basis, 'item' => $itemKey, 'area' => $areaKey, 'location' => $location,
            'selected_area' => $selected, 'summary' => $this->summary($selectedFacts),
            'coverage' => ['all_checkouts' => $allCount, 'cancelled' => $cancelled,
                'structured_district' => $facts->where('structured_district', true)->count(),
                'structured_city' => $facts->where('structured_city', true)->count(),
                'unknown_attribution' => $facts->where('attribution.type', 'unknown')->count(),
                'eligible' => $facts->count()],
            'area_options' => $areas->map(fn (array $row): array => ['key' => $row['key'], 'label' => $row['label']]),
            'item_options' => $options,
            'areas' => $export ? $areas : $this->page($areas, $request, 'page'),
            'items' => $export ? $items : $this->page($items, $request, 'items_page'),
            'campaigns' => $export ? $campaigns : $this->page($campaigns, $request, 'campaigns_page'),
        ];
    }

    private function enrich(Collection $facts): Collection
    {
        return $facts->chunk(200)->flatMap(function (Collection $batch): Collection {
            $orders = Order::query()->whereIn('checkout_group_key', $batch->pluck('key'))
                ->with('marketingCart')->orderBy('id')->get(['id', 'checkout_group_key', 'delivery_details', 'order_source'])
                ->groupBy('checkout_group_key');

            return $batch->map(function (array $row) use ($orders): array {
                $group = $orders->get($row['key'], collect());
                $first = $group->firstWhere('id', $row['first_order_id']);
                $delivery = $first?->delivery_details ?? [];
                $attributed = $group->first(fn (Order $order): bool => MarketingAttribution::sanitize((array) data_get($order->delivery_details, 'marketing_attribution', [])) !== [])
                    ?? $group->first(fn (Order $order): bool => MarketingAttribution::sanitize($order->marketingCart?->only(MarketingAttribution::KEYS) ?? []) !== []) ?? $first;
                $attribution = $attributed ? MarketingAttribution::forOrder($attributed) : MarketingAttribution::present([]);
                $row['attribution'] = array_intersect_key($attribution, array_flip([
                    'type', 'label', 'source', 'medium', 'campaign_name', 'campaign_id', 'campaign', 'adset_name', 'adset_id', 'ad_name', 'ad_id', 'content',
                ]));
                $row['geography'] = $this->geography($delivery);
                $row['structured_district'] = filled($delivery['bosta_district_id'] ?? null);
                $row['structured_city'] = filled($delivery['bosta_zone_id'] ?? null);
                $row['net_cents'] = max(0, $row['items_total_cents'] - $row['discount_cents']);
                $gross = max(0, $row['items_total_cents']);
                $cumulative = 0;
                $allocated = 0;
                $row['items'] = collect($row['items'])->map(function (array $item) use ($row, $gross, &$cumulative, &$allocated): array {
                    $cumulative += max(0, $item['total_cents']);
                    $next = $gross > 0 ? intdiv($row['net_cents'] * $cumulative, $gross) : 0;
                    $item['net_cents'] = $next - $allocated;
                    $allocated = $next;
                    $item['catalog_key'] = $item['story_id'] ? 'story:'.$item['story_id'] : ($item['product_id'] ? 'product:'.$item['product_id'] : 'legacy:'.hash('sha256', $item['type'].'|'.$item['title']));

                    return $item;
                })->all();

                return $row;
            });
        })->values();
    }

    private function geography(array $delivery): array
    {
        $country = trim((string) ($delivery['country'] ?? '')) ?: 'دولة غير محددة';
        $governorate = trim((string) ($delivery['governorate'] ?? '')) ?: 'محافظة غير محددة';
        // Bosta city = governorate, zone = city/centre, district = detailed area.
        $city = trim((string) (($delivery['bosta_zone_other_name'] ?? null) ?: ($delivery['bosta_zone_name'] ?? ''))) ?: 'مدينة/مركز غير مسجل';
        $district = trim((string) (($delivery['bosta_district_other_name'] ?? null) ?: ($delivery['bosta_district_name'] ?? null) ?: ($delivery['city'] ?? '')));
        $legacy = ! filled($delivery['bosta_district_id'] ?? null) && $district !== '';
        $district = $district ?: 'منطقة غير مسجلة';
        $parts = [
            (string) (($delivery['delivery_country_id'] ?? null) ?: mb_strtolower($country)),
            (string) (($delivery['delivery_governorate_id'] ?? null) ?: ($delivery['bosta_city_id'] ?? null) ?: mb_strtolower($governorate)),
        ];
        $result = ['governorate' => ['key' => hash('sha256', json_encode($parts)), 'label' => $country.' / '.$governorate, 'legacy' => false]];
        $parts[] = (string) (($delivery['bosta_zone_id'] ?? null) ?: mb_strtolower($city));
        $result['city'] = ['key' => hash('sha256', json_encode($parts)), 'label' => $country.' / '.$governorate.' / '.$city, 'legacy' => false];
        $parts[] = (string) (($delivery['bosta_district_id'] ?? null) ?: mb_strtolower($district));
        $result['district'] = ['key' => hash('sha256', json_encode($parts)), 'label' => $country.' / '.$governorate.' / '.$city.' / '.$district, 'legacy' => $legacy];

        return $result;
    }

    private function summary(Collection $rows): array
    {
        $customers = $rows->groupBy('customer_key');
        $net = (int) $rows->sum('net_cents');

        return ['checkouts' => $rows->count(), 'customers' => $customers->count(),
            'repeat_customers' => $customers->filter(fn (Collection $same): bool => $same->count() > 1)->count(),
            'quantity' => (int) $rows->sum('items_quantity'), 'net_cents' => $net,
            'average_cents' => $rows->isEmpty() ? 0 : (int) round($net / $rows->count()),
            'collected_cents' => (int) $rows->sum('period_collection_cents'),
            'paid_checkouts' => $rows->where('sale_recognized', true)->count(),
            'delivered' => $rows->where('fully_delivered', true)->count()];
    }

    private function items(Collection $rows): Collection
    {
        return $rows->flatMap(fn (array $row): array => collect($row['items'])->map(fn (array $item): array => $item + ['checkout' => $row['key']])->all())
            ->groupBy('catalog_key')->map(fn (Collection $items): array => [
                'key' => $items->first()['catalog_key'], 'title' => $items->first()['title'], 'type' => $items->first()['type'],
                'checkouts' => $items->pluck('checkout')->unique()->count(), 'quantity' => (int) $items->sum('quantity'),
                'gross_cents' => (int) $items->sum('total_cents'), 'net_cents' => (int) $items->sum('net_cents'),
            ])->sortBy([['quantity', 'desc'], ['net_cents', 'desc'], ['key', 'asc']])->values();
    }

    private function page(Collection $rows, Request $request, string $name): LengthAwarePaginator
    {
        $page = max(1, $request->integer($name, 1));

        return (new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, [
            'path' => route('admin.advertising-report.index'), 'pageName' => $name,
        ]))->appends($request->query());
    }
}
