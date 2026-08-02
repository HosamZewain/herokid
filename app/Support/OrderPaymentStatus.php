<?php

namespace App\Support;

class OrderPaymentStatus
{
    public const UNPAID = 'unpaid';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID_WITHOUT_SHIPPING = 'paid_without_shipping';

    public const PAID_IN_FULL = 'paid_in_full';

    public const STATUSES = [
        self::UNPAID,
        self::PARTIALLY_PAID,
        self::PAID_WITHOUT_SHIPPING,
        self::PAID_IN_FULL,
    ];

    public static function labels(): array
    {
        return [
            self::UNPAID => 'غير مدفوع',
            self::PARTIALLY_PAID => 'مدفوع جزئياً',
            self::PAID_WITHOUT_SHIPPING => 'مدفوع بدون شحن',
            self::PAID_IN_FULL => 'مدفوع كلياً',
        ];
    }

    public static function label(?string $status): string
    {
        return self::labels()[$status] ?? self::labels()[self::UNPAID];
    }

    public static function colors(): array
    {
        return [
            self::UNPAID => 'bg-slate-100 text-slate-700',
            self::PARTIALLY_PAID => 'bg-amber-100 text-amber-800',
            self::PAID_WITHOUT_SHIPPING => 'bg-sky-100 text-sky-800',
            self::PAID_IN_FULL => 'bg-emerald-100 text-emerald-800',
        ];
    }

    public static function paymentMethods(): array
    {
        return collect([
            'نقدي',
            'فودافون كاش',
            'انستاباي',
            'تحويل بنكي',
            'كارت',
            ...setting_array('payment_methods'),
            'أخرى',
        ])->map(fn (mixed $method): string => trim((string) $method))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
