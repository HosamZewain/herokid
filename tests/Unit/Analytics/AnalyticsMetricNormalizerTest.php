<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\AnalyticsMetricNormalizer;
use PHPUnit\Framework\TestCase;

class AnalyticsMetricNormalizerTest extends TestCase
{
    public function test_percentage_handles_zero_and_missing_previous_values(): void
    {
        $this->assertNull(AnalyticsMetricNormalizer::percentage(10, 0));
        $this->assertNull(AnalyticsMetricNormalizer::percentage(null, 10));
        $this->assertSame(50.0, AnalyticsMetricNormalizer::percentage(15, 10));
        $this->assertSame(-20.0, AnalyticsMetricNormalizer::percentage(8, 10));
    }

    public function test_ratio_prevents_division_by_zero(): void
    {
        $this->assertNull(AnalyticsMetricNormalizer::ratio(5, 0));
        $this->assertNull(AnalyticsMetricNormalizer::ratio(null, 10));
        $this->assertSame(25.0, AnalyticsMetricNormalizer::ratio(5, 20));
    }
}
