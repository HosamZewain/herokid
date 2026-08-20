<?php

namespace App\Support;

class OrderSource
{
    public static function options(): array
    {
        return [
            'website' => 'الموقع',
            'mobile' => 'تطبيق الهاتف',
            'whatsapp' => 'واتساب',
            'phone' => 'مكالمة هاتفية',
            'in_person' => 'زيارة',
            'social' => 'رسالة سوشيال ميديا',
            'other' => 'أخرى',
        ];
    }

    public static function manualOptions(): array
    {
        return collect(self::options())->except('website')->all();
    }

    public static function label(?string $source): string
    {
        return self::options()[$source ?: 'website'] ?? 'أخرى';
    }
}
