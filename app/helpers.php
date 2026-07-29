<?php

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        $settings = Cache::rememberForever('site_settings', function () {
            try {
                return Setting::query()->pluck('value', 'key')->toArray();
            } catch (Throwable) {
                return [];
            }
        });

        return array_key_exists($key, $settings) && $settings[$key] !== '' ? $settings[$key] : $default;
    }
}

if (! function_exists('setting_array')) {
    function setting_array(string $key): array
    {
        $value = setting($key);

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, fn ($item) => $item !== null && $item !== '')) : [];
    }
}

if (! function_exists('homepage_section_enabled')) {
    function homepage_section_enabled(string $section): bool
    {
        $definition = config("homepage.sections.{$section}");

        if (! is_array($definition) || empty($definition['setting'])) {
            return false;
        }

        $default = ($definition['default'] ?? true) ? '1' : '0';

        return (string) setting($definition['setting'], $default) === '1';
    }
}

if (! function_exists('arabic_number')) {
    function arabic_number(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return strtr((string) $value, [
            '0' => '٠',
            '1' => '١',
            '2' => '٢',
            '3' => '٣',
            '4' => '٤',
            '5' => '٥',
            '6' => '٦',
            '7' => '٧',
            '8' => '٨',
            '9' => '٩',
        ]);
    }
}

if (! function_exists('format_money')) {
    function format_money(int|float|string|null $amount, bool $withCurrency = true): string
    {
        $formatted = arabic_number(number_format((float) ($amount ?? 0), 0));

        return $withCurrency ? trim($formatted.' '.setting('currency_label', setting('currency_symbol', ''))) : $formatted;
    }
}

if (! function_exists('format_age_range')) {
    function format_age_range(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'كل الأعمار';
        }

        $formatted = preg_replace('/\s*[-–—]\s*/u', '–', trim($value));

        return arabic_number($formatted);
    }
}

if (! function_exists('delivery_range')) {
    function delivery_range(bool $withBusinessDays = true): string
    {
        $min = (int) setting('delivery_days_min', 0);
        $max = (int) setting('delivery_days_max', 0);

        if ($min <= 0 && $max <= 0) {
            return '';
        }

        if ($max > 0 && $max !== $min) {
            $range = arabic_number($min).'–'.arabic_number($max);
        } else {
            $range = arabic_number($min ?: $max);
        }

        return $withBusinessDays ? $range.' أيام عمل' : $range.' أيام';
    }
}

if (! function_exists('shipping_fee_range')) {
    function shipping_fee_range(): ?string
    {
        try {
            $countryFees = DeliveryCountry::query()
                ->where('active', true)
                ->pluck('delivery_fee')
                ->filter(fn ($fee) => $fee !== null)
                ->map(fn ($fee) => (float) $fee);

            $governorateFees = DeliveryGovernorate::query()
                ->where('active', true)
                ->with('country')
                ->get()
                ->map(fn (DeliveryGovernorate $governorate) => $governorate->effectiveDeliveryFee());

            $fees = $countryFees->merge($governorateFees)->filter(fn ($fee) => $fee >= 0);

            if ($fees->isEmpty()) {
                return null;
            }

            $min = $fees->min();
            $max = $fees->max();

            if ((float) $min === (float) $max) {
                return format_money($min);
            }

            return format_money($min, false).'–'.format_money($max);
        } catch (Throwable) {
            return null;
        }
    }
}
