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
