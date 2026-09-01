<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

class AppDateTime
{
    public static function timezone(): string
    {
        return (string) config('display.timezone', 'Africa/Cairo');
    }

    public static function display(CarbonInterface|DateTimeInterface|string|null $date): ?CarbonImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }

        $value = $date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse($date, 'UTC');

        return $value->setTimezone(static::timezone());
    }

    public static function format(CarbonInterface|DateTimeInterface|string|null $date, string $format, string $fallback = '—'): string
    {
        return static::display($date)?->format($format) ?? $fallback;
    }

    public static function human(CarbonInterface|DateTimeInterface|string|null $date, string $fallback = '—'): string
    {
        return static::display($date)?->diffForHumans(static::display(now())) ?? $fallback;
    }

    public static function utcStartOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, static::timezone())->startOfDay()->utc();
    }

    public static function utcEndOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, static::timezone())->endOfDay()->utc();
    }
}
