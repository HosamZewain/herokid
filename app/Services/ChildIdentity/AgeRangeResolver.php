<?php

namespace App\Services\ChildIdentity;

use App\Models\Story;
use Illuminate\Validation\ValidationException;

class AgeRangeResolver
{
    public function available(): array
    {
        $configured = collect(setting_array('age_ranges'))
            ->filter(fn ($range): bool => is_string($range) && trim($range) !== '')
            ->map(fn (string $range): string => trim($range))
            ->unique()
            ->values();

        if ($configured->isNotEmpty()) {
            return $configured->all();
        }

        return Story::query()
            ->where('active', true)
            ->whereNotNull('age_range')
            ->pluck('age_range')
            ->filter(fn ($range): bool => is_string($range) && trim($range) !== '')
            ->map(fn (string $range): string => trim($range))
            ->unique(fn (string $range): string => $this->normalized($range))
            ->values()
            ->all();
    }

    public function selected(string $range): string
    {
        $normalized = $this->normalized($range);
        $selected = collect($this->available())
            ->first(fn (string $candidate): bool => $this->normalized($candidate) === $normalized);

        if (! $selected) {
            throw ValidationException::withMessages([
                'age_range' => 'اختر فئة عمرية متاحة من القائمة.',
            ]);
        }

        return $selected;
    }

    public function resolve(int $age): string
    {
        foreach ($this->available() as $range) {
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
