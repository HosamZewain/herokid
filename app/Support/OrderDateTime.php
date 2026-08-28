<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class OrderDateTime
{
    public static function timezone(): string
    {
        return (string) config('orders.display_timezone', 'Africa/Cairo');
    }

    public static function display(?CarbonInterface $date): ?CarbonImmutable
    {
        return $date === null
            ? null
            : CarbonImmutable::instance($date)->setTimezone(self::timezone());
    }

    public static function utcStartOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->startOfDay()->utc();
    }

    public static function utcEndOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->endOfDay()->utc();
    }
}
