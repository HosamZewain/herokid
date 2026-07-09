<?php

namespace App\Services\Analytics;

class AnalyticsMetricNormalizer
{
    public static function number(null|int|float|string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    public static function integer(null|int|float|string $value): ?int
    {
        $number = self::number($value);

        return $number === null ? null : (int) round($number);
    }

    public static function percentage(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || abs($previous) < 0.000001) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    public static function ratio(?float $part, ?float $whole): ?float
    {
        if ($part === null || $whole === null || $whole <= 0) {
            return null;
        }

        return round(($part / $whole) * 100, 2);
    }

    public static function secondsLabel(?float $seconds): string
    {
        if ($seconds === null) {
            return 'غير متاح';
        }

        $seconds = max(0, (int) round($seconds));
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $minutes > 0 ? $minutes.'د '.$remaining.'ث' : $remaining.'ث';
    }
}
