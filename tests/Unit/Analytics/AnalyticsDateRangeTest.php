<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\AnalyticsDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

class AnalyticsDateRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['analytics.ga4.timezone' => 'Africa/Cairo']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', 'Africa/Cairo'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_default_range_is_last_seven_days(): void
    {
        $range = AnalyticsDateRange::fromRequest(Request::create('/admin/analytics'));

        $this->assertSame('last_7_days', $range->key);
        $this->assertSame('2026-07-04', $range->startDate);
        $this->assertSame('2026-07-10', $range->endDate);
    }

    public function test_custom_range_swaps_dates_when_needed(): void
    {
        $range = AnalyticsDateRange::fromRequest(Request::create('/admin/analytics', 'GET', [
            'range' => 'custom',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-01',
        ]));

        $this->assertSame('custom', $range->key);
        $this->assertSame('2026-07-01', $range->startDate);
        $this->assertSame('2026-07-09', $range->endDate);
    }
}
