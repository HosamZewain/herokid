<?php

namespace App\Support;

class OrderWorkflowStatus
{
    public const PRINTING_NOT_REQUIRED = 'not_required';

    public const PRINTING_NOT_STARTED = 'not_started';

    public const PRINTING_READY = 'ready';

    public const PRINTING_IN_PROGRESS = 'in_progress';

    public const PRINTING_COMPLETED = 'completed';

    public const PRINTING_ON_HOLD = 'on_hold';

    public const SHIPPING_NOT_REQUIRED = 'not_required';

    public const SHIPPING_NOT_READY = 'not_ready';

    public const SHIPPING_READY = 'ready';

    public const SHIPPING_SHIPPED = 'shipped';

    public const SHIPPING_DELIVERED = 'delivered';

    public const SHIPPING_RETURNED = 'returned';

    public const SHIPPING_CANCELLED = 'cancelled';

    public const PRINTING_STATUSES = [
        self::PRINTING_NOT_REQUIRED,
        self::PRINTING_NOT_STARTED,
        self::PRINTING_READY,
        self::PRINTING_IN_PROGRESS,
        self::PRINTING_COMPLETED,
        self::PRINTING_ON_HOLD,
    ];

    public const SHIPPING_STATUSES = [
        self::SHIPPING_NOT_REQUIRED,
        self::SHIPPING_NOT_READY,
        self::SHIPPING_READY,
        self::SHIPPING_SHIPPED,
        self::SHIPPING_DELIVERED,
        self::SHIPPING_RETURNED,
        self::SHIPPING_CANCELLED,
    ];

    public static function printingLabels(): array
    {
        return [
            self::PRINTING_NOT_REQUIRED => 'لا يحتاج طباعة',
            self::PRINTING_NOT_STARTED => 'لم تبدأ الطباعة',
            self::PRINTING_READY => 'جاهز للطباعة',
            self::PRINTING_IN_PROGRESS => 'جاري الطباعة',
            self::PRINTING_COMPLETED => 'اكتملت الطباعة',
            self::PRINTING_ON_HOLD => 'الطباعة متوقفة',
        ];
    }

    public static function shippingLabels(): array
    {
        return [
            self::SHIPPING_NOT_REQUIRED => 'لا يحتاج شحن',
            self::SHIPPING_NOT_READY => 'غير جاهز للشحن',
            self::SHIPPING_READY => 'جاهز للشحن',
            self::SHIPPING_SHIPPED => 'تم الشحن',
            self::SHIPPING_DELIVERED => 'تم التسليم',
            self::SHIPPING_RETURNED => 'مرتجع',
            self::SHIPPING_CANCELLED => 'الشحن ملغي',
        ];
    }

    public static function printingLabel(?string $status): string
    {
        return self::printingLabels()[$status] ?? self::printingLabels()[self::PRINTING_NOT_STARTED];
    }

    public static function shippingLabel(?string $status): string
    {
        return self::shippingLabels()[$status] ?? self::shippingLabels()[self::SHIPPING_NOT_READY];
    }

    public static function printingColors(): array
    {
        return [
            self::PRINTING_NOT_REQUIRED => 'bg-gray-100 text-gray-600',
            self::PRINTING_NOT_STARTED => 'bg-slate-100 text-slate-700',
            self::PRINTING_READY => 'bg-teal-100 text-teal-800',
            self::PRINTING_IN_PROGRESS => 'bg-indigo-100 text-indigo-800',
            self::PRINTING_COMPLETED => 'bg-emerald-100 text-emerald-800',
            self::PRINTING_ON_HOLD => 'bg-amber-100 text-amber-800',
        ];
    }

    public static function shippingColors(): array
    {
        return [
            self::SHIPPING_NOT_REQUIRED => 'bg-gray-100 text-gray-600',
            self::SHIPPING_NOT_READY => 'bg-slate-100 text-slate-700',
            self::SHIPPING_READY => 'bg-sky-100 text-sky-800',
            self::SHIPPING_SHIPPED => 'bg-cyan-100 text-cyan-800',
            self::SHIPPING_DELIVERED => 'bg-emerald-100 text-emerald-800',
            self::SHIPPING_RETURNED => 'bg-orange-100 text-orange-800',
            self::SHIPPING_CANCELLED => 'bg-red-100 text-red-800',
        ];
    }
}
