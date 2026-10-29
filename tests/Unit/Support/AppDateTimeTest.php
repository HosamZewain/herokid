<?php

namespace Tests\Unit\Support;

use App\Support\AppDateTime;
use App\Support\OrderDateTime;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AppDateTimeTest extends TestCase
{
    public function test_utc_timestamp_is_displayed_in_cairo_time(): void
    {
        config(['display.timezone' => 'Africa/Cairo']);

        $utc = CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC');

        $this->assertSame('2026-08-31 15:00:00', AppDateTime::format($utc, 'Y-m-d H:i:s'));
    }

    public function test_cairo_calendar_day_is_converted_to_utc_query_boundaries(): void
    {
        config(['display.timezone' => 'Africa/Cairo']);

        $this->assertSame('2026-08-30 21:00:00', AppDateTime::utcStartOfDay('2026-08-31')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 20:59:59.999999', AppDateTime::utcEndOfDay('2026-08-31')->format('Y-m-d H:i:s.u'));
    }

    public function test_cairo_transition_days_include_the_whole_calendar_day(): void
    {
        config(['display.timezone' => 'Africa/Cairo', 'orders.display_timezone' => 'Africa/Cairo']);
        foreach ([['2026-10-29', '2026-10-29 21:59:59.999999', 25],
            ['2026-10-30', '2026-10-30 21:59:59.999999', 24],
            ['2026-04-24', '2026-04-24 20:59:59.999999', 23]] as [$day, $end, $hours]) {
            foreach ([AppDateTime::class, OrderDateTime::class] as $helper) {
                $this->assertSame($end, $helper::utcEndOfDay($day)->format('Y-m-d H:i:s.u'));
                $this->assertEquals($hours, $helper::utcStartOfDay($day)->diffInHours($helper::utcEndOfDay($day)->addMicrosecond()));
            }
        }
        foreach (['2026-10-29 20:30:00', '2026-10-29 21:30:00'] as $utc) {
            $this->assertSame('2026-10-29 23:30', AppDateTime::format($utc, 'Y-m-d H:i'));
            $this->assertTrue(CarbonImmutable::parse($utc, 'UTC')->betweenIncluded(AppDateTime::utcStartOfDay('2026-10-29'), AppDateTime::utcEndOfDay('2026-10-29')));
        }
    }

    public function test_legacy_order_timezone_override_remains_supported(): void
    {
        config([
            'display.timezone' => 'Africa/Cairo',
            'orders.display_timezone' => 'Europe/London',
        ]);

        $utc = CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC');

        $this->assertSame('2026-08-31 13:00:00', OrderDateTime::display($utc)?->format('Y-m-d H:i:s'));
    }
}
