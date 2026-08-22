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

    public static function labels(bool $activeOnly = true): array
    {
        return OrderStatusRegistry::labels(OrderStatusRegistry::TYPE_PAYMENT, $activeOnly);
    }

    public static function label(?string $status): string
    {
        return OrderStatusRegistry::label(OrderStatusRegistry::TYPE_PAYMENT, $status, 'غير مدفوع');
    }

    public static function colors(): array
    {
        return OrderStatusRegistry::colors(OrderStatusRegistry::TYPE_PAYMENT);
    }

    public static function statuses(bool $activeOnly = true): array
    {
        return OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_PAYMENT, $activeOnly);
    }

    public static function behavior(?string $status): string
    {
        return OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_PAYMENT, $status) ?? self::UNPAID;
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
