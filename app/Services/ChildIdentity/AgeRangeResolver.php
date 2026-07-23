<?php

namespace App\Services\ChildIdentity;

use Illuminate\Validation\ValidationException;

class AgeRangeResolver
{
    public function resolve(int $age): string
    {
        foreach (setting_array('age_ranges') as $range) {
            $normalized = strtr((string) $range, [
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]);
            preg_match_all('/\d+/', $normalized, $matches);
            $numbers = array_map('intval', $matches[0] ?? []);

            if (count($numbers) >= 2 && $age >= $numbers[0] && $age <= $numbers[1]) {
                return (string) $range;
            }

            if (count($numbers) === 1 && $age === $numbers[0]) {
                return (string) $range;
            }
        }

        throw ValidationException::withMessages([
            'child_age' => 'لا توجد قصص متاحة لهذا العمر ضمن فئات الأعمار الحالية.',
        ]);
    }

    public function normalized(string $range): string
    {
        return preg_replace('/\s+/u', '', strtr($range, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '–' => '-', '—' => '-', 'إلى' => '-',
        ])) ?: '';
    }
}
