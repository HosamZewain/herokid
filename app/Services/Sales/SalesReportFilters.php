<?php

namespace App\Services\Sales;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SalesReportFilters
{
    public function __construct(
        public readonly string $range,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $status,
        public readonly string $paymentStatus,
        public readonly string $type,
        public readonly ?string $item,
        public readonly string $customerType,
        public readonly ?int $countryId,
        public readonly ?int $governorateId,
        public readonly ?string $source,
        public readonly ?string $search,
        public readonly ?float $minimumTotal,
        public readonly ?float $maximumTotal,
        public readonly string $groupBy,
        public readonly string $sort,
        public readonly int $perPage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $timezone = (string) config('app.timezone', 'Africa/Cairo');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $range = in_array($request->query('range'), [
            'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'custom',
        ], true) ? (string) $request->query('range') : 'last_30_days';

        [$start, $end] = self::dates($request, $range, $today);

        $status = (string) $request->query('status', 'all');
        $allowedStatuses = ['active', 'all', 'new', 'under_review', 'generating', 'preview_uploaded', 'revision_requested', 'approved_for_print', 'printing', 'shipped', 'delivered', 'cancelled'];
        $status = in_array($status, $allowedStatuses, true) ? $status : 'all';

        $paymentStatus = (string) $request->query('payment_status', 'all');
        $allowedPaymentStatuses = ['all', 'unpaid', 'partially_paid', 'paid_without_shipping', 'paid_in_full'];
        $paymentStatus = in_array($paymentStatus, $allowedPaymentStatuses, true) ? $paymentStatus : 'all';

        $type = (string) $request->query('type', 'all');
        $type = in_array($type, ['all', 'story', 'product', 'product_add_on'], true) ? $type : 'all';

        $customerType = (string) $request->query('customer_type', 'all');
        $customerType = in_array($customerType, ['all', 'registered', 'guest'], true) ? $customerType : 'all';

        $groupBy = (string) $request->query('group_by', 'auto');
        $groupBy = in_array($groupBy, ['auto', 'day', 'week', 'month'], true) ? $groupBy : 'auto';

        $sort = (string) $request->query('sort', 'newest');
        $sort = in_array($sort, ['newest', 'oldest', 'highest', 'lowest'], true) ? $sort : 'newest';

        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $minimumTotal = self::nonNegativeNumber($request->query('min_total'));
        $maximumTotal = self::nonNegativeNumber($request->query('max_total'));
        if ($minimumTotal !== null && $maximumTotal !== null && $maximumTotal < $minimumTotal) {
            [$minimumTotal, $maximumTotal] = [$maximumTotal, $minimumTotal];
        }

        return new self(
            range: $range,
            startDate: $start->toDateString(),
            endDate: $end->toDateString(),
            status: $status,
            paymentStatus: $paymentStatus,
            type: $type,
            item: self::item((string) $request->query('item', '')),
            customerType: $customerType,
            countryId: $request->filled('country_id') ? max(1, $request->integer('country_id')) : null,
            governorateId: $request->filled('governorate_id') ? max(1, $request->integer('governorate_id')) : null,
            source: self::shortText($request->query('source')),
            search: self::shortText($request->query('q')),
            minimumTotal: $minimumTotal,
            maximumTotal: $maximumTotal,
            groupBy: $groupBy,
            sort: $sort,
            perPage: $perPage,
        );
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->startDate, (string) config('app.timezone', 'Africa/Cairo'))->startOfDay();
    }

    public function end(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->endDate, (string) config('app.timezone', 'Africa/Cairo'))->endOfDay();
    }

    public function previousPeriod(): self
    {
        $days = $this->start()->startOfDay()->diffInDays($this->end()->startOfDay()) + 1;
        $end = $this->start()->subDay()->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();

        return new self(
            range: 'comparison',
            startDate: $start->toDateString(),
            endDate: $end->toDateString(),
            status: $this->status,
            paymentStatus: $this->paymentStatus,
            type: $this->type,
            item: $this->item,
            customerType: $this->customerType,
            countryId: $this->countryId,
            governorateId: $this->governorateId,
            source: $this->source,
            search: $this->search,
            minimumTotal: $this->minimumTotal,
            maximumTotal: $this->maximumTotal,
            groupBy: $this->groupBy,
            sort: $this->sort,
            perPage: $this->perPage,
        );
    }

    public function resolvedGroupBy(): string
    {
        if ($this->groupBy !== 'auto') {
            return $this->groupBy;
        }

        $days = $this->start()->startOfDay()->diffInDays($this->end()->startOfDay()) + 1;

        return match (true) {
            $days <= 45 => 'day',
            $days <= 180 => 'week',
            default => 'month',
        };
    }

    public function label(): string
    {
        return match ($this->range) {
            'today' => 'اليوم',
            'yesterday' => 'أمس',
            'last_7_days' => 'آخر 7 أيام',
            'last_30_days' => 'آخر 30 يوماً',
            'this_month' => 'هذا الشهر',
            'last_month' => 'الشهر الماضي',
            'this_year' => 'هذا العام',
            default => 'فترة مخصصة',
        };
    }

    private static function dates(Request $request, string $range, CarbonImmutable $today): array
    {
        return match ($range) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->startOfYear(), $today],
            'custom' => self::customDates($request, $today),
            default => [$today->subDays(29), $today],
        };
    }

    private static function customDates(Request $request, CarbonImmutable $today): array
    {
        $start = self::safeDate((string) $request->query('start_date'), $today->subDays(29));
        $end = self::safeDate((string) $request->query('end_date'), $today);

        return $end->lt($start) ? [$end, $start] : [$start, $end];
    }

    private static function safeDate(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        try {
            return $value === '' ? $fallback : CarbonImmutable::parse($value, (string) config('app.timezone', 'Africa/Cairo'))->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private static function nonNegativeNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0, round((float) $value, 2));
    }

    private static function shortText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), 100, '');
    }

    private static function item(string $value): ?string
    {
        return preg_match('/^(story|product):[1-9][0-9]*$/', $value) === 1 ? $value : null;
    }
}
