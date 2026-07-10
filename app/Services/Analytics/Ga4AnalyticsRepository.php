<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;

class Ga4AnalyticsRepository
{
    public function __construct(
        private readonly Ga4AnalyticsClient $client,
        private readonly LocalCartAnalyticsRepository $localCartAnalytics,
    ) {}

    public function dashboard(AnalyticsDateRange $range): array
    {
        if (! $this->client->isConfigured()) {
            return [
                'status' => 'setup_required',
                'configured' => false,
                'property_id' => $this->client->propertyId(),
                'message' => 'إعدادات Google Analytics غير مكتملة. أضف GA4_PROPERTY_ID وبيانات service account.',
                'range' => $range,
            ];
        }

        try {
            return [
                'status' => 'ready',
                'configured' => true,
                'property_id' => $this->client->propertyId(),
                'range' => $range,
                'summary' => $this->summary(),
                'chart' => $this->dailyChart($range),
                'sources' => $this->trafficSources($range),
                'source_details' => $this->sourceDetails($range),
                'popular_pages' => $this->popularPages($range),
                'funnel' => $this->localCartAnalytics->funnel($range),
                'local_cart_summary' => $this->localCartAnalytics->cartSummary(),
                'devices' => $this->dimensionBreakdown($range, 'deviceCategory', 'devices'),
                'locations' => $this->dimensionBreakdown($range, ['country', 'city'], 'locations'),
                'landing_pages' => $this->landingPages($range),
                'campaigns' => $this->dimensionBreakdown($range, ['sessionCampaignName', 'sessionSourceMedium'], 'campaigns'),
            ];
        } catch (Ga4ApiException $exception) {
            return [
                'status' => 'error',
                'configured' => true,
                'property_id' => $this->client->propertyId(),
                'message' => 'تعذر تحميل بيانات Google Analytics حالياً. حاول التحديث بعد قليل.',
                'range' => $range,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function widget(): array
    {
        if (! $this->client->isConfigured()) {
            return ['status' => 'setup_required'];
        }

        try {
            $summary = $this->summary();

            return [
                'status' => 'ready',
                'active_users_30m' => $summary['active_users_30m']['value'] ?? null,
                'users_today' => $summary['users_today']['value'] ?? null,
                'sessions_today' => $summary['sessions_today']['value'] ?? null,
            ];
        } catch (Ga4ApiException) {
            return ['status' => 'error'];
        }
    }

    public function flushForProperty(): void
    {
        $propertyId = $this->client->propertyId() ?: 'missing';
        $registryKey = $this->registryKey($propertyId);
        $keys = Cache::get($registryKey, []);

        foreach (is_array($keys) ? $keys : [] as $key) {
            Cache::forget($key);
        }

        Cache::forget($registryKey);
        $this->localCartAnalytics->flush();
    }

    private function summary(): array
    {
        $timezone = (string) (config('analytics.ga4.timezone') ?: config('app.timezone', 'Africa/Cairo'));
        $today = now($timezone)->toDateString();
        $yesterday = now($timezone)->subDay()->toDateString();

        $realtime = $this->remember('realtime:active-users', (int) config('analytics.ga4.realtime_cache_ttl'), function (): ?int {
            $report = $this->client->runRealtimeReport([
                'metrics' => [['name' => 'activeUsers']],
            ]);

            return AnalyticsMetricNormalizer::integer($this->metricValue($report, 0));
        });

        $daily = $this->remember('summary:'.$yesterday.':'.$today, (int) config('analytics.ga4.cache_ttl'), function () use ($yesterday, $today): array {
            $report = $this->client->runReport([
                'dateRanges' => [['startDate' => $yesterday, 'endDate' => $today]],
                'dimensions' => [['name' => 'date']],
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'newUsers'],
                    ['name' => 'totalRevenue'],
                ],
                'limit' => 2,
            ]);

            return collect($report['rows'] ?? [])->mapWithKeys(function (array $row): array {
                $date = (string) ($row['dimensionValues'][0]['value'] ?? '');

                return [$date => [
                    'users' => AnalyticsMetricNormalizer::integer($row['metricValues'][0]['value'] ?? null),
                    'sessions' => AnalyticsMetricNormalizer::integer($row['metricValues'][1]['value'] ?? null),
                    'views' => AnalyticsMetricNormalizer::integer($row['metricValues'][2]['value'] ?? null),
                    'new_users' => AnalyticsMetricNormalizer::integer($row['metricValues'][3]['value'] ?? null),
                    'revenue' => AnalyticsMetricNormalizer::number($row['metricValues'][4]['value'] ?? null),
                ]];
            })->all();
        });

        $todayKey = str_replace('-', '', $today);
        $yesterdayKey = str_replace('-', '', $yesterday);
        $todayData = $daily[$todayKey] ?? [];
        $yesterdayData = $daily[$yesterdayKey] ?? [];
        $localSummary = $this->localCartAnalytics->todaySummary();

        return [
            'active_users_30m' => ['label' => 'نشطون آخر 30 دقيقة', 'value' => $realtime, 'change' => null],
            'users_today' => $this->metricCard('المستخدمون اليوم', $todayData['users'] ?? null, $yesterdayData['users'] ?? null),
            'sessions_today' => $this->metricCard('الجلسات اليوم', $todayData['sessions'] ?? null, $yesterdayData['sessions'] ?? null),
            'views_today' => $this->metricCard('مشاهدات الصفحات اليوم', $todayData['views'] ?? null, $yesterdayData['views'] ?? null),
            'new_users_today' => $this->metricCard('مستخدمون جدد اليوم', $todayData['new_users'] ?? null, $yesterdayData['new_users'] ?? null),
            'purchases_today' => $localSummary['purchases_today'],
            'revenue_today' => $localSummary['revenue_today'],
            'conversion_rate_today' => $localSummary['conversion_rate_today'],
        ];
    }

    private function dailyChart(AnalyticsDateRange $range): array
    {
        return $this->remember('chart:'.$range->cacheSuffix(), (int) config('analytics.ga4.breakdown_cache_ttl'), function () use ($range): array {
            $report = $this->client->runReport([
                'dateRanges' => [['startDate' => $range->startDate, 'endDate' => $range->endDate]],
                'dimensions' => [['name' => 'date']],
                'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions']],
                'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
                'limit' => 366,
            ]);

            return collect($report['rows'] ?? [])->map(fn (array $row): array => [
                'date' => (string) ($row['dimensionValues'][0]['value'] ?? ''),
                'users' => AnalyticsMetricNormalizer::integer($row['metricValues'][0]['value'] ?? null),
                'sessions' => AnalyticsMetricNormalizer::integer($row['metricValues'][1]['value'] ?? null),
            ])->values()->all();
        });
    }

    private function trafficSources(AnalyticsDateRange $range): array
    {
        return $this->remember('sources:'.$range->cacheSuffix(), (int) config('analytics.ga4.breakdown_cache_ttl'), function () use ($range): array {
            $report = $this->client->runReport([
                'dateRanges' => [['startDate' => $range->startDate, 'endDate' => $range->endDate]],
                'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions']],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => 25,
            ]);
            $localConversions = $this->localCartAnalytics->sourceConversions($range);

            return collect($report['rows'] ?? [])->map(function (array $row) use ($localConversions): array {
                $source = (string) ($row['dimensionValues'][0]['value'] ?? 'Other');

                return [
                    'source' => $source === '' ? 'Other' : $source,
                    'users' => AnalyticsMetricNormalizer::integer($row['metricValues'][0]['value'] ?? null),
                    'sessions' => AnalyticsMetricNormalizer::integer($row['metricValues'][1]['value'] ?? null),
                    'conversions' => $localConversions[$source] ?? null,
                ];
            })->values()->all();
        });
    }

    private function sourceDetails(AnalyticsDateRange $range): array
    {
        return $this->dimensionBreakdown($range, 'sessionSourceMedium', 'source-medium', 15);
    }

    private function popularPages(AnalyticsDateRange $range): array
    {
        return $this->remember('popular-pages:'.$range->cacheSuffix(), (int) config('analytics.ga4.breakdown_cache_ttl'), function () use ($range): array {
            $report = $this->client->runReport([
                'dateRanges' => [['startDate' => $range->startDate, 'endDate' => $range->endDate]],
                'dimensions' => [['name' => 'pageTitle'], ['name' => 'pagePath']],
                'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers'], ['name' => 'averageSessionDuration']],
                'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                'limit' => 40,
            ]);

            return collect($report['rows'] ?? [])
                ->map(fn (array $row): array => [
                    'title' => (string) ($row['dimensionValues'][0]['value'] ?? 'بدون عنوان'),
                    'path' => (string) ($row['dimensionValues'][1]['value'] ?? '/'),
                    'views' => AnalyticsMetricNormalizer::integer($row['metricValues'][0]['value'] ?? null),
                    'users' => AnalyticsMetricNormalizer::integer($row['metricValues'][1]['value'] ?? null),
                    'average_engagement' => AnalyticsMetricNormalizer::number($row['metricValues'][2]['value'] ?? null),
                ])
                ->reject(fn (array $page): bool => $this->isTechnicalPath($page['path']))
                ->take(10)
                ->values()
                ->all();
        });
    }

    private function landingPages(AnalyticsDateRange $range): array
    {
        return $this->dimensionBreakdown($range, 'landingPagePlusQueryString', 'landing-pages', 10);
    }

    private function dimensionBreakdown(AnalyticsDateRange $range, string|array $dimensions, string $name, int $limit = 12): array
    {
        $dimensionList = collect((array) $dimensions)->map(fn (string $dimension): array => ['name' => $dimension])->values()->all();

        return $this->remember($name.':'.$range->cacheSuffix(), (int) config('analytics.ga4.breakdown_cache_ttl'), function () use ($range, $dimensionList, $limit): array {
            $report = $this->client->runReport([
                'dateRanges' => [['startDate' => $range->startDate, 'endDate' => $range->endDate]],
                'dimensions' => $dimensionList,
                'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions']],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);

            return collect($report['rows'] ?? [])->map(fn (array $row): array => [
                'label' => collect($row['dimensionValues'] ?? [])->pluck('value')->filter()->implode(' / ') ?: 'غير معروف',
                'users' => AnalyticsMetricNormalizer::integer($row['metricValues'][0]['value'] ?? null),
                'sessions' => AnalyticsMetricNormalizer::integer($row['metricValues'][1]['value'] ?? null),
            ])->values()->all();
        });
    }

    private function metricCard(string $label, mixed $current, mixed $previous): array
    {
        $current = AnalyticsMetricNormalizer::number($current);
        $previous = AnalyticsMetricNormalizer::number($previous);

        return [
            'label' => $label,
            'value' => $current,
            'change' => AnalyticsMetricNormalizer::percentage($current, $previous),
        ];
    }

    private function metricValue(array $report, int $metricIndex): mixed
    {
        return $report['rows'][0]['metricValues'][$metricIndex]['value'] ?? null;
    }

    private function remember(string $name, int $seconds, \Closure $callback): mixed
    {
        $propertyId = $this->client->propertyId() ?: 'missing';
        $key = 'analytics:ga4:'.$propertyId.':'.$name;
        $this->registerCacheKey($propertyId, $key);

        return Cache::remember($key, max(1, $seconds), $callback);
    }

    private function registerCacheKey(string $propertyId, string $key): void
    {
        $registryKey = $this->registryKey($propertyId);
        $keys = Cache::get($registryKey, []);
        $keys = is_array($keys) ? $keys : [];

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put($registryKey, $keys, now()->addDay());
        }
    }

    private function registryKey(string $propertyId): string
    {
        return 'analytics:ga4:'.$propertyId.':cache-keys';
    }

    private function isTechnicalPath(string $path): bool
    {
        return str($path)->startsWith(['/admin', '/login', '/register'])
            || str_contains($path, 'callback')
            || str_contains($path, 'webhook')
            || str_contains($path, 'payment');
    }
}
