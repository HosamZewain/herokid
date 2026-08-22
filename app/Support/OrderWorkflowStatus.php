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

    public static function printingLabels(bool $activeOnly = true): array
    {
        return OrderStatusRegistry::labels(OrderStatusRegistry::TYPE_PRINTING, $activeOnly);
    }

    public static function shippingLabels(bool $activeOnly = true): array
    {
        return OrderStatusRegistry::labels(OrderStatusRegistry::TYPE_SHIPPING, $activeOnly);
    }

    public static function printingLabel(?string $status): string
    {
        return OrderStatusRegistry::label(OrderStatusRegistry::TYPE_PRINTING, $status, 'لم تبدأ الطباعة');
    }

    public static function shippingLabel(?string $status): string
    {
        return OrderStatusRegistry::label(OrderStatusRegistry::TYPE_SHIPPING, $status, 'غير جاهز للشحن');
    }

    public static function printingColors(): array
    {
        return OrderStatusRegistry::colors(OrderStatusRegistry::TYPE_PRINTING);
    }

    public static function shippingColors(): array
    {
        return OrderStatusRegistry::colors(OrderStatusRegistry::TYPE_SHIPPING);
    }

    public static function printingStatuses(bool $activeOnly = true): array
    {
        return OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_PRINTING, $activeOnly);
    }

    public static function shippingStatuses(bool $activeOnly = true): array
    {
        return OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_SHIPPING, $activeOnly);
    }
}
