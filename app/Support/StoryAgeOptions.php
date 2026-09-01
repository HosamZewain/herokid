<?php

namespace App\Support;

final class StoryAgeOptions
{
    public const MINIMUM_PERSONALIZATION_AGE = 2;

    public const MAXIMUM_PERSONALIZATION_AGE = 16;

    /** @return array<int, int> */
    public static function forPersonalization(): array
    {
        return range(self::MINIMUM_PERSONALIZATION_AGE, self::MAXIMUM_PERSONALIZATION_AGE);
    }

    /** @return array<int, int> */
    public static function fromRange(?string $ageRange): array
    {
        $normalized = strtr((string) $ageRange, [
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);

        preg_match_all('/\d+/', $normalized, $matches);
        $ages = array_values(array_unique(array_map('intval', $matches[0] ?? [])));

        if (count($ages) >= 2) {
            $minimum = max(1, min($ages[0], $ages[1]));
            $maximum = min(18, max($ages[0], $ages[1]));

            return range($minimum, $maximum);
        }

        if (count($ages) === 1 && $ages[0] >= 1 && $ages[0] <= 18) {
            return [$ages[0]];
        }

        return range(1, 18);
    }
}
