<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AnalyticsDateRange
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $startDate,
        public readonly string $endDate,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $timezone = (string) (config('analytics.ga4.timezone') ?: config('app.timezone', 'Africa/Cairo'));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $key = (string) $request->query('range', 'last_7_days');

        return match ($key) {
            'today' => new self('today', 'اليوم', $today->toDateString(), $today->toDateString()),
            'yesterday' => new self('yesterday', 'أمس', $today->subDay()->toDateString(), $today->subDay()->toDateString()),
            'last_30_days' => new self('last_30_days', 'آخر 30 يوم', $today->subDays(29)->toDateString(), $today->toDateString()),
            'custom' => self::custom($request, $today),
            default => new self('last_7_days', 'آخر 7 أيام', $today->subDays(6)->toDateString(), $today->toDateString()),
        };
    }

    private static function custom(Request $request, CarbonImmutable $today): self
    {
        $start = self::safeDate((string) $request->query('start_date'), $today->subDays(6));
        $end = self::safeDate((string) $request->query('end_date'), $today);

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return new self('custom', 'فترة مخصصة', $start->toDateString(), $end->toDateString());
    }

    private static function safeDate(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value, (string) (config('analytics.ga4.timezone') ?: config('app.timezone', 'Africa/Cairo')))->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public function cacheSuffix(): string
    {
        return $this->key.':'.$this->startDate.':'.$this->endDate;
    }
}
